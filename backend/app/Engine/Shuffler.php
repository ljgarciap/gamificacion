<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Barajado de Durstenfeld (Fisher-Yates) alimentado por el [Drbg]. Todas las permutaciones son igual de
 * probables porque cada paso elige uniformemente (sin sesgo) entre las posiciones restantes.
 *
 * No se usa la función de barajado de PHP: es de propósito general, no reproducible desde una semilla
 * revelada y no permite a un tercero verificar el resultado.
 */
final class Shuffler
{
    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function permute(array $items, Drbg $drbg): array
    {
        $a = array_values($items);
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = $drbg->uniform($i + 1);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }

        return $a;
    }
}
