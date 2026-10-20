<?php

namespace App\Domain\Redemption;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Límite de intentos fallidos con BLOQUEO PROGRESIVO (SPEC-002 R1). Por usuario y por IP, cada uno con sus propios umbrales.
 *
 * Al superar `max_failures` dentro de la ventana, el usuario (o la IP) queda bloqueado `lockout_base_seconds`; la siguiente vez el
 * doble, y así hasta `lockout_max_seconds`. El nivel de bloqueo "se enfría" tras 24 h sin nuevos bloqueos. Un canje exitoso reinicia el
 * contador de fallos del usuario (no el nivel): un error de tipeo legítimo no se acumula contra quien después acierta.
 *
 * Las claves de caché usan un hash del identificador: la caché no guarda ids de usuario ni IPs en claro.
 */
final class RedeemThrottle
{
    private const LEVEL_TTL = 86_400;

    public function assertAllowed(string $userId, ?string $ip): void
    {
        $now = Carbon::now()->getTimestamp();
        $worst = 0;
        foreach ($this->scopes($userId, $ip) as [$scope, $id]) {
            $until = (int) Cache::get($this->key('lock', $scope, $id), 0);
            $worst = max($worst, $until - $now);
        }
        if ($worst > 0) {
            throw new RedeemFailure(RedeemFailure::RATE_LIMITED, $worst);
        }
    }

    public function recordFailure(string $userId, ?string $ip): void
    {
        foreach ($this->scopes($userId, $ip) as [$scope, $id]) {
            $cfg = (array) config("gamification.redeem.$scope");
            $failKey = $this->key('fail', $scope, $id);

            Cache::add($failKey, 0, (int) $cfg['window_seconds']);
            $failures = (int) Cache::increment($failKey);
            if ($failures < (int) $cfg['max_failures']) {
                continue;
            }

            $levelKey = $this->key('level', $scope, $id);
            Cache::add($levelKey, 0, self::LEVEL_TTL);
            $level = (int) Cache::increment($levelKey);
            $seconds = (int) min($cfg['lockout_base_seconds'] * (2 ** ($level - 1)), $cfg['lockout_max_seconds']);

            Cache::put($this->key('lock', $scope, $id), Carbon::now()->getTimestamp() + $seconds, $seconds);
            Cache::forget($failKey);
        }
    }

    public function recordSuccess(string $userId): void
    {
        Cache::forget($this->key('fail', 'user', hash('sha256', $userId)));
    }

    /** @return list<array{0: string, 1: string}> */
    private function scopes(string $userId, ?string $ip): array
    {
        $scopes = [['user', hash('sha256', $userId)]];
        if ($ip !== null && $ip !== '') {
            $scopes[] = ['ip', hash('sha256', $ip)];
        }

        return $scopes;
    }

    private function key(string $kind, string $scope, string $id): string
    {
        return "gamif:throttle:$kind:$scope:$id";
    }
}
