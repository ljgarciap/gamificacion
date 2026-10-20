<?php

namespace App\Domain\Balance;

/**
 * Saldo del usuario en COP. La billetera es de la app host (SPEC-002 D2: el host acredita); cada host implementa esto contra
 * su propio sistema. [StubBalanceProvider] sirve para el modo independiente y las demostraciones.
 */
interface BalanceProvider
{
    public function balanceFor(string $userId): int;
}
