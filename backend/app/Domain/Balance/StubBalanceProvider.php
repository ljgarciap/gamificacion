<?php

namespace App\Domain\Balance;

use App\Domain\Demo\DemoAccess;
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

        // Las sesiones de la muestra (SPEC-006) llevan su saldo inicial y sus cargas por compra en el libro; el saldo inicial del entorno no aplica.
        $ledger = (int) DB::selectOne('SELECT COALESCE(SUM(amount), 0) AS total FROM demo_wallet_ledger WHERE user_id = ?', [$userId])->total;
        $initial = DemoAccess::isDemoSubjectUser($userId) ? 0 : (int) config('gamification.demo.initial_balance');

        return $initial + $won + $ledger;
    }
}
