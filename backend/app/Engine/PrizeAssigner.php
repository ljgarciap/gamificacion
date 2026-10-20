<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Asigna un premio a cada índice de código: baraja el pool expandido con el flujo determinista de S'.
 * Función pura: mismos (pool, semilla, baliza, lote) => misma asignación, siempre.
 */
final class PrizeAssigner
{
    /**
     * @return list<string> premio de cada índice de código (posición 0 = primer código)
     */
    public static function assign(Pool $pool, string $seed, string $beacon, string $batchId): array
    {
        $mixed = Commitment::mixSeed($seed, $beacon, $batchId);

        return Shuffler::permute($pool->expanded(), new Drbg($mixed));
    }
}
