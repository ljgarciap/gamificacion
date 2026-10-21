<?php

namespace App\Domain\Campaigns;

/**
 * Cuántos giros concede un premio «giro extra» (`meta.extra_spins`). Es un entero entre 1 y [MAX]; sin el campo vale 1. Un valor raro
 * (negativo, texto, decimal, enorme) rompería la cuenta de la reserva y haría fallar canjes con un error 500 permanente, así que se
 * rechaza al definir el premio y, al comprometer un lote, se trata como error duro.
 */
final class ExtraSpins
{
    public const MAX = 10;

    /**
     * @param  array<string, mixed>|null  $meta
     *
     * @throws \InvalidArgumentException si el premio es de giro extra y su valor no es válido, o si otro tipo de premio lo trae
     */
    public static function fromMeta(string $type, ?array $meta): int
    {
        $has = $meta !== null && array_key_exists('extra_spins', $meta);
        if ($type !== 'extraSpin') {
            if ($has) {
                throw new \InvalidArgumentException('«extra_spins» solo se admite en un premio de tipo extraSpin.');
            }

            return 0;
        }
        $value = $has ? $meta['extra_spins'] : 1;
        if (! is_int($value) || $value < 1 || $value > self::MAX) {
            throw new \InvalidArgumentException('«extra_spins» debe ser un entero entre 1 y '.self::MAX.'.');
        }

        return $value;
    }

    /** Igual que [fromMeta] pero sin lanzar: lo que ya estaba guardado con un valor inválido no concede giros (en vez de romper el canje). */
    public static function lenient(string $type, ?array $meta): int
    {
        try {
            return self::fromMeta($type, $meta);
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }
}
