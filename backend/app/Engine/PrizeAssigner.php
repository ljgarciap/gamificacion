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

    /**
     * Baraja el mazo completo (N + R) y lo parte: las N primeras posiciones son de códigos y las R siguientes, la reserva.
     * Con R = 0 es EXACTAMENTE [assign] (ruta v1, sin cambios).
     *
     * @return array{codes: list<string>, reserve: list<string>}
     */
    public static function assignDeck(Pool $pool, int $reserve, string $seed, string $beacon, string $batchId): array
    {
        if ($reserve === 0) {
            return ['codes' => self::assign($pool, $seed, $beacon, $batchId), 'reserve' => []];
        }
        Commitment::assertReserve($pool, $reserve);
        $deck = Shuffler::permute($pool->expanded(), new Drbg(Commitment::mixSeedV2($seed, $beacon, $batchId)));
        $n = count($deck) - $reserve;

        return ['codes' => array_slice($deck, 0, $n), 'reserve' => array_slice($deck, $n)];
    }
}
