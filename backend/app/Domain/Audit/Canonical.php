<?php

namespace App\Domain\Audit;

/**
 * JSON canónico: claves ordenadas recursivamente, sin espacios, UTF-8 y barras sin escapar. Lo que se escribe y lo que se
 * relee de la base (jsonb reordena y normaliza) produce SIEMPRE el mismo texto, requisito para que el hash se pueda recomputar.
 * Restricción: sin números decimales (su representación varía); usar enteros o cadenas.
 */
final class Canonical
{
    public static function json(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException('la bitácora no admite decimales: use enteros o cadenas');
        }
        if (! is_array($value)) {
            return $value;
        }
        $normalized = array_map(self::normalize(...), $value);
        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }
}
