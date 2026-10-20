<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Regla de aceptación del muestreo uniforme (rejection sampling).
 *
 * `x % n` directamente sesga el resultado cuando n no divide al rango: los valores bajos salen más veces.
 * La solución es descartar las entradas que caen en el tramo final incompleto, y aquí vive esa decisión.
 * Está separada y parametrizada por rango para poder probarla de forma EXACTA con un rango pequeño
 * (el motor usa 2^32; el test enumera los 256 valores de un byte y cuenta uno por uno).
 */
final class Uniform
{
    /**
     * Resultado en [0, n) para la entrada $word en [0, $range), o null si la entrada se descarta.
     */
    public static function accept(int $word, int $n, int $range): ?int
    {
        if ($n < 1 || $n > $range) {
            throw new \InvalidArgumentException('n fuera de rango');
        }
        $limit = intdiv($range, $n) * $n;

        return $word < $limit ? $word % $n : null;
    }
}
