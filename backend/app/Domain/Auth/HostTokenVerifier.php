<?php

namespace App\Domain\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;

/**
 * Valida el JWT que entrega la app host (SPEC-002 R9). El `userId` sale del token, NUNCA del cuerpo de la petición.
 *
 * Reglas:
 *  - El emisor (`iss`) debe estar en la configuración; su algoritmo y su clave los fija la configuración. El encabezado `alg` del
 *    token se IGNORA como fuente de verdad: si no coincide con el configurado, la verificación falla (no hay `alg: none` ni
 *    confusión HS256/RS256).
 *  - Obligatorios: `exp`, `iat`, `sub` y `aud` (que incluya la audiencia configurada). Vida máxima acotada (`exp - iat`).
 *  - Se usa la biblioteca firebase/php-jwt para la criptografía; aquí solo se decide QUÉ clave y QUÉ reglas aplican.
 */
final class HostTokenVerifier
{
    private const ALGORITHMS = ['HS256', 'RS256', 'ES256'];

    /** @param array<string, array{alg?: string, key?: string}> $hosts */
    public function __construct(
        private readonly array $hosts,
        private readonly string $audience,
        private readonly int $maxLifetimeSeconds,
        private readonly int $leewaySeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (array) config('gamification.hosts'),
            (string) config('gamification.token.audience'),
            \App\Domain\Settings\Settings::int('token.max_lifetime_seconds'),
            \App\Domain\Settings\Settings::int('token.leeway_seconds'),
        );
    }

    /** @throws TokenInvalid */
    public function verify(string $jwt): HostUser
    {
        $issuer = $this->unverifiedIssuer($jwt);
        $host = $this->hosts[$issuer] ?? null;
        if (! is_array($host)) {
            throw new TokenInvalid('emisor desconocido');
        }
        $alg = $host['alg'] ?? null;
        $key = $host['key'] ?? null;
        if (! in_array($alg, self::ALGORITHMS, true) || ! is_string($key) || $key === '') {
            throw new TokenInvalid("configuración incompleta del emisor $issuer");
        }
        $material = $alg === 'HS256' ? base64_decode($key, true) : $key;
        if ($material === false || $material === '') {
            throw new TokenInvalid("clave del emisor $issuer inválida");
        }

        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = $this->leewaySeconds;
        JWT::$timestamp = Carbon::now()->getTimestamp(); // respeta el reloj de pruebas (Carbon::setTestNow)
        try {
            $claims = JWT::decode($jwt, new Key($material, $alg));
        } catch (\Throwable $e) {
            throw new TokenInvalid('token rechazado: '.$e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        return $this->checkClaims($claims, $issuer);
    }

    private function checkClaims(object $claims, string $issuer): HostUser
    {
        if (($claims->iss ?? null) !== $issuer) {
            throw new TokenInvalid('iss inconsistente');
        }
        $sub = $claims->sub ?? null;
        if (! is_string($sub) || $sub === '' || strlen($sub) > 128 || preg_match('/^[\x21-\x7E]+$/', $sub) !== 1) {
            throw new TokenInvalid('sub ausente o inválido');
        }
        $aud = $claims->aud ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($this->audience, $audiences, true)) {
            throw new TokenInvalid('audiencia incorrecta');
        }
        $iat = $claims->iat ?? null;
        $exp = $claims->exp ?? null;
        if (! is_int($iat) || ! is_int($exp)) {
            throw new TokenInvalid('iat y exp son obligatorios');
        }
        $lifetime = $exp - $iat;
        if ($lifetime <= 0 || $lifetime > $this->maxLifetimeSeconds) {
            throw new TokenInvalid('vida del token fuera de lo permitido');
        }

        return new HostUser($issuer, $sub);
    }

    /** Lee `iss` SIN verificar, solo para elegir qué clave usar; después la firma se valida con esa clave fija. */
    private function unverifiedIssuer(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $parts[1] === '') {
            throw new TokenInvalid('formato de token inválido');
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $json = base64_decode($b64.str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        $payload = $json === false ? null : json_decode($json, true);
        $iss = is_array($payload) ? ($payload['iss'] ?? null) : null;
        if (! is_string($iss) || $iss === '') {
            throw new TokenInvalid('token sin emisor');
        }

        return $iss;
    }
}
