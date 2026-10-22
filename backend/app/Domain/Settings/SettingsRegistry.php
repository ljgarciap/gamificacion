<?php

namespace App\Domain\Settings;

/**
 * Registro de parámetros administrables (SPEC-003 §9): clave (la misma ruta que en `config/gamification.php`), tipo entero, mínimo y máximo
 * DUROS y si es de seguridad. Los límites son suelos/techos de seguridad (nunca 0 intentos, nunca un token de más de 1 h); el superadmin fija
 * los valores de negocio DENTRO de ellos. Un parámetro de seguridad exige control dual (RF-43).
 */
final class SettingsRegistry
{
    /** @return array<string, array{min: int, max: int, security: bool, label: string, unit: string}> */
    public static function all(): array
    {
        $d = static fn (string $label, string $unit, int $min, int $max, bool $security): array => compact('label', 'unit', 'min', 'max', 'security');
        $defs = [
            'token.max_lifetime_seconds' => $d('Vida máxima del token del host', 's', 60, 3600, true),
            'token.leeway_seconds' => $d('Tolerancia de reloj del token', 's', 0, 120, true),
        ];
        foreach (['user' => 'por usuario', 'ip' => 'por IP'] as $scope => $text) {
            $defs["redeem.$scope.max_failures"] = $d("Fallos permitidos al redimir ($text)", 'intentos', 1, $scope === 'ip' ? 1000 : 50, true);
            $defs["redeem.$scope.window_seconds"] = $d("Ventana de fallos al redimir ($text)", 's', 60, 86400, true);
            $defs["redeem.$scope.lockout_base_seconds"] = $d("Bloqueo inicial al redimir ($text)", 's', 1, 3600, true);
            $defs["redeem.$scope.lockout_max_seconds"] = $d("Bloqueo máximo al redimir ($text)", 's', 60, 86400, true);
        }
        $defs += [
            'redeem.requests_per_minute' => $d('Peticiones por minuto al redimir', 'peticiones', 1, 600, true),
            'host.timeout_seconds' => $d('Tiempo máximo de una llamada al host', 's', 1, 30, true),
            'host.connect_timeout_seconds' => $d('Tiempo máximo para conectar con el host', 's', 1, 10, true),
            'host.signature_tolerance_seconds' => $d('Tolerancia de reloj en las firmas del host', 's', 30, 900, true),
            'host.breaker_failures' => $d('Fallos seguidos del saldo antes de cortar al host', 'fallos', 1, 100, true),
            'host.breaker_open_seconds' => $d('Tiempo que se corta al host tras fallar', 's', 1, 600, true),
            'outbox.max_attempts' => $d('Reintentos de aviso al host', 'intentos', 1, 30, false),
            'outbox.backoff_base_seconds' => $d('Espera inicial entre reintentos', 's', 1, 3600, false),
            'outbox.backoff_max_seconds' => $d('Espera máxima entre reintentos', 's', 1, 86400, false),
            'admin.password_min_length' => $d('Largo mínimo de contraseña del panel', 'caracteres', 12, 128, true),
            'admin.login.max_failures' => $d('Fallos de inicio de sesión por cuenta', 'intentos', 1, 20, true),
            'admin.login.lockout_base_seconds' => $d('Bloqueo inicial de cuenta', 's', 1, 3600, true),
            'admin.login.lockout_max_seconds' => $d('Bloqueo máximo de cuenta', 's', 60, 86400, true),
            'admin.login.ip_max_failures' => $d('Fallos de inicio de sesión por IP', 'intentos', 1, 200, true),
            'admin.login.ip_window_seconds' => $d('Ventana de fallos por IP', 's', 60, 86400, true),
            'admin.login.ip_lockout_seconds' => $d('Bloqueo por IP', 's', 60, 86400, true),
            'admin.pending_ttl_seconds' => $d('Tiempo entre contraseña y segundo factor', 's', 60, 1800, true),
            'admin.session_absolute_hours' => $d('Duración máxima de la sesión del panel', 'h', 1, 24, true),
            'admin.approval_ttl_hours' => $d('Caducidad de una solicitud de control dual', 'h', 1, 168, true),
            'admin.export_ttl_minutes' => $d('Tiempo para descargar los códigos generados', 'min', 5, 1440, true),
            'admin.lookup_per_minute' => $d('Búsquedas de código por minuto y por usuario del panel', 'búsquedas', 1, 300, true),
            'admin.audit_verify_per_minute' => $d('Verificaciones de la bitácora por minuto y por usuario del panel', 'verificaciones', 1, 120, false),
            'admin.totp_window' => $d('Ventana de tolerancia del código TOTP', 'pasos', 0, 1, true),
        ];

        return $defs;
    }

    /** @return array{min: int, max: int, security: bool, label: string, unit: string}|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
