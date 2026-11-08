<?php

namespace App\Domain\Dev;

use Firebase\JWT\JWT;

/** Firma el JWT del "host de mentira" (modo desarrollo y muestra). Pasa por el mismo verificador que cualquier host real. */
final class DevTokenIssuer
{
    public const LIFETIME = 300;

    /**
     * @return array{token: string, expiresIn: int, userId: string}
     *
     * @throws DevIssuerNotConfigured
     */
    public static function issue(string $subject, int $lifetime = self::LIFETIME): array
    {
        $issuer = (string) config('gamification.dev.issuer');
        $host = config("gamification.hosts.$issuer");
        $key = is_array($host) && ($host['alg'] ?? null) === 'HS256' && is_string($host['key'] ?? null) ? base64_decode($host['key'], true) : false;
        if ($key === false || $key === '') {
            throw new DevIssuerNotConfigured("El emisor de desarrollo «{$issuer}» no está en GAMIF_HOSTS con alg HS256.");
        }
        $now = time();
        $token = JWT::encode([
            'iss' => $issuer, 'sub' => $subject, 'aud' => (string) config('gamification.token.audience'),
            'iat' => $now, 'exp' => $now + $lifetime,
        ], $key, 'HS256');

        return ['token' => $token, 'expiresIn' => $lifetime, 'userId' => $issuer.':'.$subject];
    }
}
