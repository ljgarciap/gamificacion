<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;

/** Emite JWT como lo haría una app host y configura al emisor en la aplicación. */
final class HostTokens
{
    public const ISSUER = 'host-a';

    public const AUDIENCE = 'gamification';

    /** Secreto HS256 de prueba (32 bytes). */
    public const SECRET = 'ssssssssssssssssssssssssssssssss';

    /** Registra al emisor de prueba en la configuración. */
    public static function configure(array $extraHosts = []): void
    {
        config([
            'gamification.hosts' => [self::ISSUER => ['alg' => 'HS256', 'key' => base64_encode(self::SECRET)]] + $extraHosts,
            'gamification.token.audience' => self::AUDIENCE,
            'gamification.token.max_lifetime_seconds' => 600,
            'gamification.token.leeway_seconds' => 30,
        ]);
    }

    /** @param array<string, mixed> $claims sobrescribe/añade claims; un valor null elimina el claim */
    public static function make(string $subject = 'usuario-1', array $claims = [], string $secret = self::SECRET, string $alg = 'HS256'): string
    {
        $now = Carbon::now()->getTimestamp();
        $payload = array_merge(
            ['iss' => self::ISSUER, 'sub' => $subject, 'aud' => self::AUDIENCE, 'iat' => $now, 'exp' => $now + 300],
            $claims,
        );
        $payload = array_filter($payload, static fn ($v): bool => $v !== null);

        return JWT::encode($payload, $secret, $alg);
    }

    /** Cabecera Authorization lista para usar. */
    public static function bearer(string $subject = 'usuario-1', array $claims = []): array
    {
        return ['Authorization' => 'Bearer '.self::make($subject, $claims)];
    }

    /** El id con el que el sistema conoce al usuario. */
    public static function userId(string $subject = 'usuario-1'): string
    {
        return self::ISSUER.':'.$subject;
    }
}
