<?php
// Recorrido de punta a punta contra el HOST SIMULADOR real (conexión TCP de verdad, no un transporte falso): aviso firmado, idempotencia,
// saldo firmado y los modos de fallo del simulador. Necesita `docker compose up -d host-sim` y APP_ENV=local (el simulador está en una red
// privada y habla http: el guard SSRF solo lo admite en local). Todo lo de la base va dentro de una transacción que se revierte.
//
//   docker compose run --rm -e APP_ENV=local -e GAMIF_HOST_ALLOW_LOCAL=true -v "$PWD/backend/scripts:/scripts" backend php /scripts/e2e_host_sim.php

use App\Domain\Admin\SecretBox;
use App\Domain\Audit\AuditLog;
use App\Domain\Balance\HttpBalanceProvider;
use App\Domain\Fulfillment\HttpPrizeFulfillment;
use App\Domain\Fulfillment\PermanentDeliveryFailure;
use App\Domain\Hosts\HostClient;
use App\Domain\Hosts\HostService;
use App\Domain\Hosts\HostUnavailable;
use App\Domain\Hosts\HostUrlGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sim = 'http://host-sim:8095';
$simSecret = getenv('HOST_SIM_SECRETS') ?: 'secreto-del-simulador-solo-desarrollo';
$fails = 0;
$check = function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? '  ok   ' : '  FALLA ').$what.PHP_EOL;
    $fails += $ok ? 0 : 1;
};
$mode = function (string $m, int $count = 0) use ($sim): void {
    file_get_contents("$sim/__sim/mode", false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['mode' => $m, 'count' => $count])]]));
};
$state = fn (): array => json_decode(file_get_contents("$sim/__sim/state"), true);
$expect = function (callable $fn, string $class) {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
};

file_get_contents("$sim/__sim/reset", false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => '{}']]));
config(['gamification.hosts' => ['e2e-host' => ['alg' => 'HS256', 'key' => 'k']]]);
echo 'BD: '.DB::connection()->getDatabaseName().' | entorno: '.app()->environment().PHP_EOL;

DB::beginTransaction();
try {
    $guard = HostUrlGuard::fromConfig();
    $svc = new HostService(new AuditLog, new SecretBox, $guard);
    $h = $svc->create(['issuer' => 'e2e-host', 'callbackUrl' => "$sim/callback", 'balanceUrl' => "$sim/balance"], 'e2e');
    // Se fija el secreto del simulador (el real lo genera el servidor y lo recibe el equipo del host).
    $box = new SecretBox;
    DB::update("UPDATE hosts SET secret_current_enc = decode(?, 'hex') WHERE id = ?", [bin2hex($box->seal($simSecret, "hosts|{$h['id']}|secret_current_enc")), $h['id']]);

    $client = new HostClient($svc, $guard);
    $fulfil = new HttpPrizeFulfillment($client);
    $balance = new HttpBalanceProvider($client);
    $notice = fn (string $id, int $amount = 10000): array => [
        'redemption_id' => $id, 'user_id' => 'e2e-host:u1', 'campaign_id' => 'c', 'batch_id' => 'b',
        'prize' => ['id' => 'p10k', 'type' => 'money', 'amount' => $amount], 'redeemed_at' => '2026-10-21T12:00:00.000000Z',
    ];

    echo "1. Aviso firmado e idempotencia\n";
    $fulfil->deliver($notice('r-1'));
    $check('el simulador acreditó 10000 a u1', ($state()['wallet']['u1'] ?? null) === 10000);
    $fulfil->deliver($notice('r-1'));
    $check('el mismo event-id entregado otra vez NO acredita dos veces', ($state()['wallet']['u1'] ?? null) === 10000);
    $check('el duplicado quedó registrado', ($state()['events']['r-1']['duplicates'] ?? 0) === 1);

    // Reenvío de un aviso capturado con OTRA cabecera X-Gamif-Event-Id: el cuerpo (firmado) no cambia, así que el host no debe acreditar de nuevo.
    $replay = function (string $eventId) use ($sim, $simSecret, $notice): int {
        $body = json_encode($notice('r-1'));
        $ts = (string) time();
        $ch = curl_init("$sim/callback");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', "X-Gamif-Event-Id: $eventId", "X-Gamif-Timestamp: $ts",
            'X-Gamif-Signature: '.hash_hmac('sha256', $ts.'.'.$body, $simSecret),
        ]]);
        curl_exec($ch);

        return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    };
    $check('un aviso capturado reenviado con otro event-id se rechaza (400)', $replay('otro-id-cualquiera') === 400);
    $check('y no acreditó de nuevo', ($state()['wallet']['u1'] ?? null) === 10000);

    echo "2. Saldo firmado\n";
    Cache::flush();
    $check('el saldo leído es 10000', $balance->balanceFor('e2e-host:u1') === 10000);

    echo "3. Respuestas de saldo inválidas del host\n";
    foreach (['badsig' => 'firma equivocada', 'unsigned' => 'sin firma', 'oldts' => 'marca de tiempo vieja', 'bigbody' => 'cuerpo enorme', 'http500' => 'HTTP 500'] as $m => $label) {
        Cache::flush();
        $mode($m, 1);
        $check("$label → HostUnavailable (nunca un saldo inventado)", $expect(fn () => $balance->balanceFor('e2e-host:u1'), HostUnavailable::class));
    }

    echo "4. Fallos del host al avisar\n";
    $mode('http500', 1);
    $check('HTTP 500 → reintentable (HostUnavailable)', $expect(fn () => $fulfil->deliver($notice('r-2')), HostUnavailable::class));
    $mode('http403', 1);
    $check('HTTP 403 → rechazo permanente', $expect(fn () => $fulfil->deliver($notice('r-3')), PermanentDeliveryFailure::class));
    $mode('slow', 1);
    $t = microtime(true);
    $check('host lento → se corta por tiempo (HostUnavailable)', $expect(fn () => $fulfil->deliver($notice('r-4')), HostUnavailable::class));
    $took = microtime(true) - $t;
    $check(sprintf('…y no esperó los 15 s del host (%.1f s)', $took), $took < 10);
    $check('lo fallido no acreditó nada', ($state()['wallet']['u1'] ?? null) === 10000);

    echo "5. Rotación: el host aún no conoce el secreto nuevo\n";
    $svc->rotate($h['id'], 'e2e');
    $check('firma con el secreto nuevo desconocido → 401 → rechazo permanente', $expect(fn () => $fulfil->deliver($notice('r-5')), PermanentDeliveryFailure::class));
    $check('el simulador anotó el rechazo por firma', count($state()['rejected']) >= 1);
    $check('y no acreditó', ($state()['wallet']['u1'] ?? null) === 10000);

    echo "6. Desactivar el host corta las llamadas\n";
    DB::update('UPDATE hosts SET active = false WHERE id = ?', [$h['id']]);
    $check('host inactivo → HostUnavailable sin llamar', $expect(fn () => $fulfil->deliver($notice('r-6')), HostUnavailable::class));
} finally {
    DB::rollBack();
    file_get_contents("$sim/__sim/reset", false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => '{}']]));
}

echo $fails === 0 ? "\nTODO OK\n" : "\n$fails comprobaciones fallaron\n";
exit($fails === 0 ? 0 : 1);
