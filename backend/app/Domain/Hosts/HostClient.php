<?php

namespace App\Domain\Hosts;

use App\Domain\Settings\Settings;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;

/**
 * Llamadas salientes firmadas a la app host (SPEC-003 RF-50/52, amenazas T18–T24). Cada llamada:
 *  - elige el host por el prefijo `emisor:` del id de usuario (el emisor más largo que coincida) y exige que esté activo;
 *  - vuelve a pasar la URL por [HostUrlGuard] y FIJA la conexión a una de las IP validadas (`CURLOPT_RESOLVE`): un DNS que cambie entre la
 *    validación y la conexión no puede desviarla (DNS rebinding); el nombre sigue siendo el de la URL para TLS y la cabecera Host;
 *  - NO sigue redirecciones, tiene tiempo máximo de conexión y total, y lee como mucho `MAX_BODY` bytes de la respuesta;
 *  - firma `timestamp "." contenido` con HMAC-SHA256 (secreto actual) y envía `X-Gamif-Event-Id/Timestamp/Signature`.
 *
 * Nunca devuelve ni registra el cuerpo de una respuesta de error del host (T24): solo el estado.
 */
final class HostClient
{
    public const MAX_BODY = 4096;

    private readonly ?Closure $handler;

    /** @param (callable(RequestInterface, array<string, mixed>): mixed)|null $handler transporte de Guzzle; SOLO para pruebas */
    public function __construct(
        private readonly HostService $hosts,
        private readonly HostUrlGuard $guard,
        ?callable $handler = null,
    ) {
        $this->handler = $handler !== null ? Closure::fromCallable($handler) : null;
    }

    /** @return array{status: int, body: string, host: object} */
    public function post(string $userId, string $eventId, string $json): array
    {
        $host = $this->hostFor($userId);

        return $this->send($host, 'POST', $host->callback_url, $json, $eventId);
    }

    /** @return array{status: int, body: string, host: object} */
    public function get(string $userId, string $subject): array
    {
        $host = $this->hostFor($userId);
        $url = $host->balance_url.(str_contains($host->balance_url, '?') ? '&' : '?').'user='.rawurlencode($subject);

        return $this->send($host, 'GET', $url, '', (string) Str::uuid());
    }

    /** ¿La firma de una respuesta del host es válida con alguno de sus secretos y la marca de tiempo está en ventana? */
    public function verifyResponse(object $host, string $body, ?string $timestamp, ?string $signature): bool
    {
        if ($timestamp === null || $signature === null || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }
        if (abs(Carbon::now()->getTimestamp() - (int) $timestamp) > Settings::int('host.signature_tolerance_seconds')) {
            return false;
        }
        $secrets = $this->hosts->secretsFor((int) $host->id);
        foreach (array_filter([$secrets['current'], $secrets['previous']]) as $secret) {
            if (hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    // --- Internos --------------------------------------------------------------------------------------------------

    private function hostFor(string $userId): object
    {
        $best = null;
        foreach (DB::select('SELECT id, issuer, callback_url, balance_url, active FROM hosts WHERE active') as $h) {
            if (str_starts_with($userId, $h->issuer.':') && ($best === null || strlen($h->issuer) > strlen($best->issuer))) {
                $best = $h;
            }
        }

        return $best ?? throw new HostUnavailable('no hay un host activo registrado para este usuario');
    }

    /** @return array{status: int, body: string, host: object} */
    private function send(object $host, string $method, string $url, string $payload, string $eventId): array
    {
        try {
            $target = $this->guard->check($url);
        } catch (HostUrlRejected $e) {
            throw new HostUnavailable('la URL del host ya no es segura: '.$e->getMessage());
        }
        $secret = $this->hosts->secretsFor((int) $host->id)['current'];
        $ts = (string) Carbon::now()->getTimestamp();
        // POST firma el cuerpo; GET (sin cuerpo) firma el destino (ruta y consulta), para que la consulta tampoco se pueda cambiar.
        $signed = $method === 'GET' ? (parse_url($url, PHP_URL_PATH) ?: '/').'?'.(parse_url($url, PHP_URL_QUERY) ?? '') : $payload;
        $signature = hash_hmac('sha256', $ts.'.'.$signed, $secret);

        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => Settings::int('host.timeout_seconds'),
            'connect_timeout' => Settings::int('host.connect_timeout_seconds'),
            // NO `stream => true`: Guzzle elegiría el manejador de flujos PHP, que ignora las opciones de cURL (la fijación de IP) y las rechaza.
            // El tope de tamaño se aplica DURANTE la descarga: se aborta en cuanto lo que llega lo supera, sin esperar a tenerlo entero.
            'progress' => static function (int $dlTotal, int $dlNow): void {
                if ($dlNow > self::MAX_BODY || $dlTotal > self::MAX_BODY) {
                    throw new \RuntimeException('respuesta demasiado grande');
                }
            },
            'verify' => true,
            'protocols' => [$target['scheme']], // solo el esquema validado, también si hubiera una redirección
            'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ips'][0]]],
        ];
        $headers = [
            'Accept' => 'application/json', 'User-Agent' => 'gamification-callback/1',
            'X-Gamif-Event-Id' => $eventId, 'X-Gamif-Timestamp' => $ts, 'X-Gamif-Signature' => $signature,
        ] + ($method === 'POST' ? ['Content-Type' => 'application/json'] : []);

        try {
            $client = new Client($this->handler !== null ? ['handler' => $this->handler] : []);
            $response = $client->send(new Request($method, $url, $headers, $method === 'POST' ? $payload : null), $options);
            // Un `read` puede devolver solo el primer trozo recibido: se lee hasta el final o hasta pasar el tope (un trozo no es la respuesta).
            $stream = $response->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= self::MAX_BODY) {
                $body .= $stream->read(self::MAX_BODY + 1 - strlen($body));
            }
            $stream->close();
        } catch (GuzzleException|\RuntimeException $e) {
            // Sin el mensaje de la excepción: puede incluir la URL completa. Solo la clase de fallo.
            throw new HostUnavailable('fallo de red al llamar al host ('.class_basename($e).')');
        }
        if (strlen($body) > self::MAX_BODY) {
            throw new HostUnavailable('la respuesta del host excede el tope de '.self::MAX_BODY.' bytes');
        }

        return ['status' => $response->getStatusCode(), 'body' => $body, 'host' => $host] + ['headers' => $response->getHeaders()];
    }
}
