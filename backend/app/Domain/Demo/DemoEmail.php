<?php

namespace App\Domain\Demo;

/**
 * Correo de las cuentas de la muestra (SPEC-006A): validación estricta y normalización. Se normaliza a minúsculas y recortado; el `+alias` y los
 * puntos NO se colapsan (T-D19: `a.b@x.com` y `ab@x.com` son cuentas distintas, a propósito). Solo ASCII (sin IDN): menos superficie (T-D17).
 */
final class DemoEmail
{
    public const MAX_LENGTH = 254;

    /** @return string|null el correo normalizado, o null si no es válido */
    public static function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $email = strtolower(trim($raw));
        if ($email === '' || strlen($email) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f-\xff,;<>()\[\]\\\\"]/', $email) === 1) {
            return null; // controles (incluye CR/LF), espacios, no ASCII y separadores que permitirían inyectar cabeceras o destinatarios
        }
        if (substr_count($email, '@') !== 1) {
            return null;
        }
        [$local, $domain] = explode('@', $email);
        if ($local === '' || strlen($local) > 64 || str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            return null;
        }
        if (preg_match('/^[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+$/D', $local) !== 1) {
            return null;
        }
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/D', $domain) !== 1) {
            return null;
        }

        return $email;
    }

    /** `a***@dominio.com`: lo único del correo que ve el panel y los registros (T-D16). */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return ($local === '' ? '' : $local[0]).'***@'.$domain;
    }
}
