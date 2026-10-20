<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Codificación binaria de la SPEC-002 §4. `‖` es concatenación; los campos de largo variable llevan su largo
 * en 4 bytes big-endian para que dos campos distintos nunca produzcan la misma cadena concatenada.
 */
final class Bytes
{
    public static function be32(int $n): string
    {
        if ($n < 0 || $n > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('be32 fuera de rango');
        }

        return pack('N', $n);
    }

    public static function be64(int $n): string
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('be64 no admite negativos');
        }

        return pack('J', $n);
    }

    /** Campo de largo variable: largo (4 bytes) + contenido. */
    public static function lp(string $data): string
    {
        return self::be32(strlen($data)).$data;
    }
}
