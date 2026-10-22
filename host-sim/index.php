<?php

/**
 * Host simulador (SPEC-003 RF-53). SOLO desarrollo y pruebas: implementa el contrato de docs/contrato-host.md con una billetera de juguete.
 *
 *   POST /callback            aviso de acreditación firmado (idempotente por X-Gamif-Event-Id)
 *   GET  /balance?user=SUB    saldo firmado
 *   POST /__sim/mode          {"mode": "ok|slow|http500|http403|http404|badsig|unsigned|oldts|bigbody", "count": N}  próximas N respuestas (0 = hasta cambiar)
 *   GET  /__sim/state         billetera, eventos recibidos y rechazos
 *   POST /__sim/reset
 *
 * Secretos aceptados: HOST_SIM_SECRETS (lista separada por comas; el PRIMERO firma las respuestas; todos verifican, como en una rotación).
 */

declare(strict_types=1);

const TOLERANCE = 300;
$secrets = array_values(array_filter(array_map('trim', explode(',', (string) getenv('HOST_SIM_SECRETS')))));
$stateFile = getenv('HOST_SIM_STATE') ?: '/tmp/host-sim-state.json';

/** Ejecuta $fn con el estado bloqueado y lo guarda. */
function withState(string $file, callable $fn): mixed
{
    $fh = fopen($file, 'c+');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $s = ($raw !== '' && $raw !== false ? json_decode($raw, true) : null) ?? ['wallet' => [], 'events' => [], 'rejected' => [], 'mode' => 'ok', 'count' => 0];
    $result = $fn($s);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($s));
    flock($fh, LOCK_UN);
    fclose($fh);

    return $result;
}

function reply(int $status, mixed $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo is_string($body) ? $body : json_encode($body);
    exit;
}

function sign(string $secret, string $ts, string $content): string
{
    return hash_hmac('sha256', $ts.'.'.$content, $secret);
}

/** @return string|null motivo del rechazo, o null si la firma y la marca de tiempo son válidas */
function verify(array $secrets, string $content): ?string
{
    $ts = $_SERVER['HTTP_X_GAMIF_TIMESTAMP'] ?? '';
    $sig = strtolower($_SERVER['HTTP_X_GAMIF_SIGNATURE'] ?? '');
    if ($ts === '' || $sig === '' || ! ctype_digit($ts)) {
        return 'faltan cabeceras de firma';
    }
    if (abs(time() - (int) $ts) > TOLERANCE) {
        return 'marca de tiempo fuera de ventana';
    }
    foreach ($secrets as $secret) {
        if (hash_equals(sign($secret, $ts, $content), $sig)) {
            return null;
        }
    }

    return 'firma inválida';
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = (string) file_get_contents('php://input');

// --- Control del simulador ----------------------------------------------------------------------------------------
if ($path === '/__sim/mode' && $method === 'POST') {
    $in = json_decode($body, true) ?: [];
    withState($stateFile, function (array &$s) use ($in): void {
        $s['mode'] = (string) ($in['mode'] ?? 'ok');
        $s['count'] = (int) ($in['count'] ?? 0);
    });
    reply(204, '');
}
if ($path === '/__sim/state') {
    reply(200, withState($stateFile, fn (array &$s): array => $s));
}
if ($path === '/__sim/reset' && $method === 'POST') {
    withState($stateFile, function (array &$s): void {
        $s = ['wallet' => [], 'events' => [], 'rejected' => [], 'mode' => 'ok', 'count' => 0];
    });
    reply(204, '');
}

// Modo de fallo vigente (consume una respuesta si tiene contador).
$mode = withState($stateFile, function (array &$s): string {
    $m = $s['mode'];
    if ($m !== 'ok' && $s['count'] > 0 && --$s['count'] === 0) {
        $s['mode'] = 'ok';
    }

    return $m;
});
if ($mode === 'slow') {
    sleep(15);
}
if ($mode === 'http500') {
    reply(500, ['error' => 'simulado']);
}
if ($mode === 'http403') {
    reply(403, ['error' => 'simulado']);
}
if ($mode === 'http404') {
    reply(404, ['error' => 'simulado']);
}

// --- Contrato ------------------------------------------------------------------------------------------------------
if ($path === '/callback' && $method === 'POST') {
    $why = verify($secrets, $body);
    $eventId = $_SERVER['HTTP_X_GAMIF_EVENT_ID'] ?? '';
    if ($why !== null || $eventId === '') {
        withState($stateFile, function (array &$s) use ($why): void {
            $s['rejected'][] = ['at' => time(), 'why' => $why ?? 'falta X-Gamif-Event-Id'];
        });
        reply(401, ['error' => 'firma o cabeceras inválidas']);
    }
    $payload = json_decode($body, true);
    if (! is_array($payload) || ! isset($payload['user_id'], $payload['prize']['type'], $payload['redemption_id'])) {
        reply(400, ['error' => 'cuerpo inválido']);
    }
    // La cabecera X-Gamif-Event-Id NO va firmada: quien capture un aviso podría reenviarlo con otro id y acreditar de nuevo. La clave de
    // idempotencia es el redemption_id del CUERPO (firmado) y la cabecera debe coincidir (revisión P10, hallazgo 4).
    if ($payload['redemption_id'] !== $eventId) {
        withState($stateFile, function (array &$s): void {
            $s['rejected'][] = ['at' => time(), 'why' => 'X-Gamif-Event-Id no coincide con redemption_id'];
        });
        reply(400, ['error' => 'X-Gamif-Event-Id debe ser igual a redemption_id']);
    }
    $duplicate = withState($stateFile, function (array &$s) use ($eventId, $payload): bool {
        if (isset($s['events'][$eventId])) {
            $s['events'][$eventId]['duplicates'] = ($s['events'][$eventId]['duplicates'] ?? 0) + 1;

            return true; // idempotente: no se acredita dos veces
        }
        $amount = in_array($payload['prize']['type'], ['money', 'points'], true) ? (int) ($payload['prize']['amount'] ?? 0) : 0;
        $sub = substr((string) $payload['user_id'], (int) strpos((string) $payload['user_id'], ':') + 1);
        $s['wallet'][$sub] = ($s['wallet'][$sub] ?? 0) + $amount;
        $s['events'][$eventId] = ['user' => $sub, 'type' => $payload['prize']['type'], 'amount' => $amount, 'duplicates' => 0];

        return false;
    });
    reply(200, ['status' => $duplicate ? 'duplicate' : 'credited']);
}

if ($path === '/balance' && $method === 'GET') {
    $target = $path.'?'.(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '');
    $why = verify($secrets, $target);
    if ($why !== null) {
        withState($stateFile, function (array &$s) use ($why): void {
            $s['rejected'][] = ['at' => time(), 'why' => $why];
        });
        reply(401, ['error' => 'firma inválida']);
    }
    parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
    $user = (string) ($q['user'] ?? '');
    $balance = withState($stateFile, fn (array &$s): int => (int) ($s['wallet'][$user] ?? 0));
    $json = json_encode(['balance' => $balance]);
    $ts = (string) ($mode === 'oldts' ? time() - 7200 : time());
    $sig = sign($secrets[0] ?? '', $ts, $json);
    if ($mode === 'badsig') {
        $sig = sign('secreto-equivocado', $ts, $json);
    }
    if ($mode === 'bigbody') {
        $json = json_encode(['balance' => $balance, 'relleno' => str_repeat('x', 10000)]);
        $sig = sign($secrets[0] ?? '', $ts, $json);
    }
    $headers = $mode === 'unsigned' ? [] : ['X-Gamif-Timestamp' => $ts, 'X-Gamif-Signature' => $sig];
    reply(200, $json, $headers);
}

reply(404, ['error' => 'no existe']);
