<?php

namespace App\Domain\Admin;

/**
 * TOTP de RFC 6238 (HMAC-SHA1, pasos de 30 s) sobre el HOTP de RFC 4226. Implementación propia, verificada con los vectores oficiales del RFC:
 * en el componente más sensible del panel se prefiere un código corto y comprobable a una dependencia más en la cadena de suministro.
 *
 * Un paso ya usado NO se acepta de nuevo (quien observó el código no puede repetirlo dentro de su ventana de validez).
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public static function code(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function step(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    /**
     * @param  int|null  $lastUsedStep  último paso aceptado para esta cuenta: se rechaza ese y cualquiera anterior
     * @return int|null el paso que coincidió (para guardarlo), o null si el código no vale
     */
    public static function verify(string $secret, string $code, int $now, ?int $lastUsedStep, int $window = 1): ?int
    {
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }
        $current = self::step($now);
        $matched = null;
        // Se comparan TODAS las posiciones de la ventana (sin salir en la primera): el tiempo no revela cuál acertó.
        for ($d = -$window; $d <= $window; $d++) {
            if (hash_equals(self::code($secret, $current + $d), $code) && $matched === null) {
                $matched = $current + $d;
            }
        }
        if ($matched === null || ($lastUsedStep !== null && $matched <= $lastUsedStep)) {
            return null;
        }

        return $matched;
    }

    public static function newSecret(): string
    {
        return random_bytes(20); // 160 bits, el tamaño de la salida de HMAC-SHA1
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.Base32::encode($secret)
            .'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }
}
