<?php

namespace App\Domain\Codes;

/**
 * Formato de los códigos promocionales (SPEC-002 RF-21, D4).
 *
 *   RULETA-K3J9-QW2X-7HD
 *   └prefijo┘ └─ 10 símbolos aleatorios (50 bits) ─┘└ 1 de verificación ┘
 *
 *  - Alfabeto Crockford de 32 símbolos (sin I, L, O, U): 10 símbolos = exactamente 50 bits, y como 32 divide a 256 no hace falta
 *    descartar valores (sin sesgo de módulo). Al ESCRIBIR, la confusión O→0 e I/L→1 se corrige al normalizar.
 *  - El símbolo de verificación (Luhn módulo 32) detecta todos los errores de un solo símbolo y casi todas las transposiciones de
 *    adyacentes; sirve para rechazar un error de tipeo antes de consultar la base de datos. NO es seguridad.
 *  - El prefijo es decorativo (identifica la campaña para quien lo lee) y se ignora: el secreto son los 11 símbolos finales.
 */
final class CodeFormat
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const PAYLOAD_LENGTH = 10;

    /** Símbolos aleatorios + el de verificación. */
    public const LENGTH = 11;

    public const MAX_PREFIX = 12;

    private const BASE = 32;

    /** Bytes que se necesitan para sacar 50 bits (7 bytes = 56 bits; se usan los 50 de arriba). */
    public const BYTES_NEEDED = 7;

    /** Valida un prefijo: 2 a 12 caracteres, mayúsculas y dígitos, empezando por letra. */
    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match('/^[A-Z][A-Z0-9]{1,'.(self::MAX_PREFIX - 1).'}$/', $prefix) === 1;
    }

    /**
     * Convierte 7 bytes aleatorios en 10 símbolos. Pura y determinista (el azar lo pone quien llama), así se puede probar de
     * forma exacta. Usa los 50 bits superiores: cada grupo de 5 bits es uniforme sobre los 32 símbolos.
     */
    public static function payloadFromBytes(string $bytes): string
    {
        if (strlen($bytes) < self::BYTES_NEEDED) {
            throw new \InvalidArgumentException('se necesitan al menos '.self::BYTES_NEEDED.' bytes');
        }
        $n = 0;
        for ($i = 0; $i < self::BYTES_NEEDED; $i++) {
            $n = ($n << 8) | ord($bytes[$i]);
        }
        $out = '';
        for ($g = 0; $g < self::PAYLOAD_LENGTH; $g++) {
            $out .= self::ALPHABET[($n >> (56 - 5 * ($g + 1))) & 31];
        }

        return $out;
    }

    /** Símbolo de verificación (Luhn módulo 32) de los 10 símbolos aleatorios. */
    public static function checkSymbol(string $payload): string
    {
        $factor = 2;
        $sum = 0;
        for ($i = strlen($payload) - 1; $i >= 0; $i--) {
            $value = strpos(self::ALPHABET, $payload[$i]);
            if ($value === false) {
                throw new \InvalidArgumentException('símbolo fuera del alfabeto');
            }
            $addend = $factor * $value;
            $factor = $factor === 2 ? 1 : 2;
            $sum += intdiv($addend, self::BASE) + ($addend % self::BASE);
        }

        return self::ALPHABET[(self::BASE - ($sum % self::BASE)) % self::BASE];
    }

    /** Código canónico de 11 símbolos (aleatorios + verificación) a partir de 7 bytes aleatorios. */
    public static function canonicalFromBytes(string $bytes): string
    {
        $payload = self::payloadFromBytes($bytes);

        return $payload.self::checkSymbol($payload);
    }

    /** Presentación para imprimir/enviar: PREFIJO-XXXX-XXXX-XXX. */
    public static function display(string $canonical, string $prefix): string
    {
        if (! self::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException('prefijo inválido');
        }
        if (strlen($canonical) !== self::LENGTH) {
            throw new \InvalidArgumentException('el código canónico debe tener '.self::LENGTH.' símbolos');
        }

        return $prefix.'-'.substr($canonical, 0, 4).'-'.substr($canonical, 4, 4).'-'.substr($canonical, 8, 3);
    }

    /**
     * Lo que el usuario escribió -> código canónico de 11 símbolos (lo que se hashea y se busca).
     * Tolera minúsculas, espacios, guiones, guiones bajos, el prefijo y las confusiones O/0 e I/L/1.
     *
     * @throws InvalidCodeFormat
     */
    public static function normalize(string $input): string
    {
        // Solo separadores ASCII se descartan; cualquier otro carácter raro (acentos, símbolos) deja el texto inválido.
        $s = strtr(strtoupper($input), ['O' => '0', 'I' => '1', 'L' => '1']);
        $s = preg_replace('/[\s\-_]+/', '', $s);
        if ($s === null || preg_match('/^[0-9A-Z]+$/', $s) !== 1) {
            throw new InvalidCodeFormat(InvalidCodeFormat::MALFORMED, 'caracteres no permitidos');
        }
        if (strlen($s) < self::LENGTH || strlen($s) > self::LENGTH + self::MAX_PREFIX) {
            throw new InvalidCodeFormat(InvalidCodeFormat::MALFORMED, 'largo incorrecto');
        }

        $code = substr($s, -self::LENGTH); // lo anterior es el prefijo y se ignora
        if (strspn($code, self::ALPHABET) !== self::LENGTH) {
            throw new InvalidCodeFormat(InvalidCodeFormat::MALFORMED, 'símbolo fuera del alfabeto');
        }
        if (self::checkSymbol(substr($code, 0, self::PAYLOAD_LENGTH)) !== $code[self::PAYLOAD_LENGTH]) {
            throw new InvalidCodeFormat(InvalidCodeFormat::CHECKSUM, 'símbolo de verificación incorrecto');
        }

        return $code;
    }
}
