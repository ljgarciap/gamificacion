<?php

namespace App\Domain\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Barrera de canje de la muestra (SPEC-006, amenazas T-D1 y T-D9). Los tokens del emisor de demostración los emite un servicio ABIERTO
 * (por diseño), así que ese emisor nunca debe poder tocar una campaña real, y un código sorteado para alguien solo lo canjea esa persona.
 * `RedeemService` la consulta ANTES de canjear; un rechazo es idéntico a un código inexistente (sin oráculo).
 */
final class DemoAccess
{
    /** Sujeto de las sesiones simuladas: prefijo configurable, punto y 24 hex aleatorios que solo conoce quien abrió la sesión. */
    public const SUBJECT_PATTERN = '/^[a-z0-9-]{1,32}\.[0-9a-f]{24}$/';

    public static function issuerPrefix(): string
    {
        return config('gamification.dev.issuer').':';
    }

    /** ¿El usuario viene del emisor abierto de demostración/desarrollo? */
    public static function isOpenIssuerUser(string $userId): bool
    {
        return str_starts_with($userId, self::issuerPrefix());
    }

    /** ¿Es una sesión simulada creada por `/v1/demo/session`? */
    public static function isDemoSubjectUser(string $userId): bool
    {
        return self::isOpenIssuerUser($userId) && preg_match(self::SUBJECT_PATTERN, substr($userId, strlen(self::issuerPrefix()))) === 1;
    }

    /**
     * @param  bool  $campaignIsDemo  la campaña del código es de demostración
     * @param  string  $codeHashHex  huella del código en hexadecimal
     */
    public static function permits(string $userId, bool $campaignIsDemo, string $codeHashHex): bool
    {
        // 1. El emisor abierto solo llega a campañas de demostración, aunque el servidor tenga otras.
        if (self::isOpenIssuerUser($userId) && ! $campaignIsDemo) {
            return false;
        }
        // 2. Un código del pool de la muestra solo lo canjea quien lo recibió, y mientras siga asignado (no disponible ni retirado). Solo hay filas
        //    de pool en campañas de demostración (lo impone un trigger), así que un canje normal no paga esta consulta.
        if ($campaignIsDemo) {
            $pool = DB::selectOne("SELECT state, assigned_to FROM demo_pool WHERE code_hash = decode(?, 'hex')", [$codeHashHex]);
            if ($pool !== null) {
                return $pool->state === 'assigned' && $pool->assigned_to === $userId;
            }
        }

        return true;
    }
}
