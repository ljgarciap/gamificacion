<?php

namespace App\Domain\Admin;

/**
 * Matriz de permisos por rol (spec-003 §5), en UN solo sitio. Toda ruta `/admin/*` declara el permiso que exige (middleware `admin.can:`) y
 * una prueba recorre cada celda de esta matriz. Aquí se AGREGAN permisos al implementar cada bloque; nunca se reparten por el código.
 */
final class AdminPermissions
{
    public const SUPERADMIN = 'superadmin';

    public const OPERATOR = 'operator';

    public const AUDITOR = 'auditor';

    public const ROLES = [self::SUPERADMIN, self::OPERATOR, self::AUDITOR];

    /** Permiso que tiene todo usuario autenticado sobre su propia cuenta (perfil, contraseña, cerrar sesión). */
    public const ACCOUNT_SELF = 'account.self';

    public const USERS_MANAGE = 'users.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const BATCHES_MANAGE = 'batches.manage';

    public const APPROVALS_VIEW = 'approvals.view';

    public const APPROVALS_REQUEST = 'approvals.request';

    public const APPROVALS_APPROVE = 'approvals.approve';

    /** @var array<string, list<string>> */
    private const MATRIX = [
        self::SUPERADMIN => [self::ACCOUNT_SELF, self::USERS_MANAGE, self::SETTINGS_MANAGE, self::BATCHES_MANAGE, self::APPROVALS_VIEW, self::APPROVALS_REQUEST, self::APPROVALS_APPROVE],
        self::OPERATOR => [self::ACCOUNT_SELF, self::BATCHES_MANAGE, self::APPROVALS_VIEW, self::APPROVALS_REQUEST, self::APPROVALS_APPROVE],
        self::AUDITOR => [self::ACCOUNT_SELF, self::APPROVALS_VIEW],
    ];

    /** @return list<string> */
    public static function for(string $role): array
    {
        return self::MATRIX[$role] ?? [];
    }

    public static function allows(string $role, string $permission): bool
    {
        return in_array($permission, self::for($role), true);
    }

    /** @return list<string> todos los permisos que existen */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::MATRIX))));
    }
}
