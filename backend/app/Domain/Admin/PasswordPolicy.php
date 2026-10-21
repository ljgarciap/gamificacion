<?php

namespace App\Domain\Admin;

use App\Domain\Settings\Settings;
/**
 * Política de contraseñas del panel. Sin llamadas a servicios externos: una lista embebida de contraseñas comunes y reglas simples.
 * Máximo 72 BYTES: bcrypt ignora en silencio lo que pase de ahí, y una contraseña "más larga" que en realidad no lo es engaña a quien la elige.
 */
final class PasswordPolicy
{
    public const MAX_BYTES = 72;

    private const COMMON = [
        'password', 'contrasena', 'contraseña', 'administrador', 'administrator', 'admin', 'qwerty', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm',
        'letmein', 'welcome', 'bienvenido', 'iloveyou', 'monkey', 'dragon', 'football', 'baseball', 'superman', 'batman', 'trustno1',
        'abc', 'abcdef', 'abcdefgh', 'abcdefghijkl', 'passw0rd', 'p4ssw0rd', 'changeme', 'cambiame', 'secret', 'secreto', 'master', 'login',
        'colombia', 'bogota', 'medellin', 'supergiros', 'gamificacion', 'gamification', 'promocion', 'premios', 'ruleta', 'raspa',
        'sunshine', 'princess', 'shadow', 'michael', 'jennifer', 'hunter', 'ranger', 'freedom', 'whatever', 'internet', 'computer',
        '123456', '1234567', '12345678', '123456789', '1234567890', '123123', '111111', '000000', '121212', '654321',
    ];

    /** @return list<string> motivos por los que NO sirve (vacío = válida) */
    public static function errors(string $password, string $email): array
    {
        $errors = [];
        $min = Settings::int('admin.password_min_length');
        if (mb_strlen($password) < $min) {
            $errors[] = "Debe tener al menos {$min} caracteres.";
        }
        if (strlen($password) > self::MAX_BYTES) {
            $errors[] = 'Debe tener como máximo '.self::MAX_BYTES.' bytes.';
        }
        $lower = mb_strtolower($password);
        $local = mb_strtolower(explode('@', $email)[0]);
        if (mb_strlen($local) >= 4 && str_contains($lower, $local)) {
            $errors[] = 'No debe contener tu correo.';
        }
        // "Password2024!" cuenta como "password": se quitan dígitos y símbolos de los extremos antes de comparar.
        $core = preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $lower) ?? $lower;
        // Sin ninguna letra (solo dígitos y símbolos: 1234567890123) es una secuencia, no una contraseña.
        if ($core === '' || in_array($lower, self::COMMON, true) || in_array($core, self::COMMON, true)) {
            $errors[] = 'Es demasiado común.';
        }
        if (count(array_unique(mb_str_split($password))) < 5) {
            $errors[] = 'Usa más variedad de caracteres.';
        }

        return $errors;
    }
}
