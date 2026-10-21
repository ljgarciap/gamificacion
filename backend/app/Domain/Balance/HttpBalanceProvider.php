<?php

namespace App\Domain\Balance;

use App\Domain\Hosts\HostClient;
use App\Domain\Hosts\HostUnavailable;
use App\Domain\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Saldo leído de la billetera del host (SPEC-003 RF-52, amenazas T21/T22). La respuesta debe venir FIRMADA por el host (mismo esquema, con
 * cualquiera de sus dos secretos) y traer `{"balance": entero ≥ 0}`; cualquier otra cosa es [HostUnavailable]: nunca se inventa un saldo.
 * Un interruptor por host corta las llamadas tras varios fallos seguidos, para que un host caído o lento no acapare procesos del canje.
 */
final class HttpBalanceProvider implements BalanceProvider
{
    private const MAX_BALANCE = 1_000_000_000_000;

    public function __construct(private readonly HostClient $client) {}

    public function balanceFor(string $userId): int
    {
        // Por host (id), no por el primer trozo del emisor: dos emisores con `:` (p. ej. `app` y `app:eu`) no comparten interruptor.
        $breaker = 'gamif:host:breaker:'.$this->client->hostFor($userId)->id;
        if ((int) Cache::get($breaker.':open_until', 0) > time()) {
            throw new HostUnavailable('el host está temporalmente cortado tras fallos seguidos');
        }

        try {
            $subject = substr($userId, strpos($userId, ':') + 1);
            $r = $this->client->get($userId, $subject);
            if ($r['status'] !== 200) {
                throw new HostUnavailable("el host respondió HTTP {$r['status']}");
            }
            if (! $this->client->verifyResponse($r['host'], $r['body'], $r['timestamp'], $r['signature'])) {
                throw new HostUnavailable('la respuesta del host no trae una firma válida');
            }
            $data = json_decode($r['body'], true);
            $balance = is_array($data) ? ($data['balance'] ?? null) : null;
            if (! is_int($balance) || $balance < 0 || $balance > self::MAX_BALANCE) {
                throw new HostUnavailable('la respuesta del host no trae un saldo válido');
            }
        } catch (HostUnavailable $e) {
            $this->recordFailure($breaker);
            throw $e;
        }
        Cache::forget($breaker.':fails');

        return $balance;
    }

    private function recordFailure(string $breaker): void
    {
        Cache::add($breaker.':fails', 0, 300);
        if ((int) Cache::increment($breaker.':fails') >= Settings::int('host.breaker_failures')) {
            Cache::put($breaker.':open_until', time() + Settings::int('host.breaker_open_seconds'), Settings::int('host.breaker_open_seconds'));
            Cache::forget($breaker.':fails');
        }
    }
}
