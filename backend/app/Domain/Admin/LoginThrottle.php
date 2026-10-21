<?php

namespace App\Domain\Admin;

use App\Domain\Redemption\RedeemFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Límite de intentos fallidos de inicio de sesión POR IP (el de la cuenta vive en la base: `failed_attempts` / `locked_until`). Pasado el máximo
 * dentro de la ventana, la IP queda bloqueada un tiempo fijo. La caché guarda un hash de la IP, no la IP.
 */
final class LoginThrottle
{
    /** @throws RedeemFailure (RATE_LIMITED) con los segundos de espera */
    public function assertAllowed(?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }
        $until = (int) Cache::get($this->key('lock', $ip), 0);
        $left = $until - Carbon::now()->getTimestamp();
        if ($left > 0) {
            throw new RedeemFailure(RedeemFailure::RATE_LIMITED, $left);
        }
    }

    public function recordFailure(?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }
        $cfg = (array) config('gamification.admin.login');
        $failKey = $this->key('fail', $ip);
        Cache::add($failKey, 0, (int) $cfg['ip_window_seconds']);
        if ((int) Cache::increment($failKey) >= (int) $cfg['ip_max_failures']) {
            $seconds = (int) $cfg['ip_lockout_seconds'];
            Cache::put($this->key('lock', $ip), Carbon::now()->getTimestamp() + $seconds, $seconds);
            Cache::forget($failKey);
        }
    }

    private function key(string $kind, string $ip): string
    {
        return "gamif:admin-login:$kind:".hash('sha256', $ip);
    }
}
