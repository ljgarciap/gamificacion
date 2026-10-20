<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Generador de bytes determinista (SPEC-002 §4): el bloque i es HMAC-SHA256(clave, "gamif/v1/shuffle" ‖ be64(i)).
 *
 * Es determinista A PROPÓSITO: la misma clave produce siempre la misma secuencia, y por eso cualquier tercero
 * puede reproducir la baraja a partir de la semilla revelada. La impredecibilidad viene de la clave (semilla
 * de 256 bits generada con el CSPRNG del sistema operativo fuera de este módulo), no de este generador.
 * Este módulo no genera azar: no usa ninguna función de azar (ver NoGeneralPurposeRandomTest).
 */
final class Drbg
{
    public const WORD_RANGE = 4294967296; // 2^32: se leen 4 bytes por intento

    private int $counter = 0;

    private string $buffer = '';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('la clave del DRBG debe tener 32 bytes');
        }
    }

    public function nextBytes(int $n): string
    {
        while (strlen($this->buffer) < $n) {
            $this->buffer .= hash_hmac('sha256', Domain::SHUFFLE.Bytes::be64($this->counter), $this->key, true);
            $this->counter++;
        }
        $out = substr($this->buffer, 0, $n);
        $this->buffer = substr($this->buffer, $n);

        return $out;
    }

    /** Entero uniforme en [0, n) sin sesgo. Con n == 1 no consume nada. */
    public function uniform(int $n): int
    {
        if ($n < 1 || $n > self::WORD_RANGE) {
            throw new \InvalidArgumentException('n fuera de rango');
        }
        if ($n === 1) {
            return 0;
        }

        do {
            $word = unpack('N', $this->nextBytes(4))[1];
            $result = Uniform::accept($word, $n, self::WORD_RANGE);
        } while ($result === null);

        return $result;
    }
}
