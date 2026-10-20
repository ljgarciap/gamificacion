<?php

namespace App\Domain\Crypto;

/** Lee y valida los secretos de config/gamification.php. Falla fuerte si falta o es débil: nunca usa un valor por defecto. */
final class Secrets
{
    public static function key(string $configKey): string
    {
        $raw = config("gamification.$configKey");
        if (! is_string($raw) || $raw === '') {
            throw new MissingSecret("Falta el secreto gamification.$configKey (ver `php artisan gamification:keys`).");
        }
        $bin = base64_decode($raw, true);
        if ($bin === false || strlen($bin) !== 32) {
            throw new MissingSecret("El secreto gamification.$configKey debe ser de 32 bytes en base64.");
        }

        return $bin;
    }
}
