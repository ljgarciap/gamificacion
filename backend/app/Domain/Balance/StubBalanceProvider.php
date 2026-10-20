<?php

namespace App\Domain\Balance;

use Illuminate\Support\Facades\DB;

/**
 * Saldo de demostración: un saldo inicial configurable más los premios en dinero que el usuario ha ganado. NO es una billetera:
 * en producción la acredita la app host al recibir el aviso de la outbox.
 */
final class StubBalanceProvider implements BalanceProvider
{
    public function balanceFor(string $userId): int
    {
        $won = (int) DB::selectOne(
            "SELECT COALESCE(SUM((response->'outcome'->'prize'->>'amount')::bigint), 0) AS total
             FROM redemptions
             WHERE user_id = ? AND response->'outcome'->'prize'->>'type' = 'money'",
            [$userId],
        )->total;

        return (int) config('gamification.demo.initial_balance') + $won;
    }
}
