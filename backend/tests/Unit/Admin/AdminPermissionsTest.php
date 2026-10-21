<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\AdminPermissions as P;
use PHPUnit\Framework\TestCase;

/** La matriz de spec-003 §5, celda por celda. Al agregar un permiso, agregar aquí su fila para cada rol. */
final class AdminPermissionsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function cells(): array
    {
        $cells = [];
        $matrix = [
            P::ACCOUNT_SELF => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => true],
            P::USERS_MANAGE => [P::SUPERADMIN => true, P::OPERATOR => false, P::AUDITOR => false],
            P::SETTINGS_MANAGE => [P::SUPERADMIN => true, P::OPERATOR => false, P::AUDITOR => false],
            P::HOSTS_MANAGE => [P::SUPERADMIN => true, P::OPERATOR => false, P::AUDITOR => false],
            P::BATCHES_MANAGE => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => false],
            P::AUDIT_EXPORT => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => false],
            P::DASHBOARD_VIEW => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => true],
            P::OUTBOX_VIEW => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => true],
            P::OUTBOX_RETRY => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => false],
            P::BATCHES_VIEW => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => true],
            P::CAMPAIGNS_VIEW => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => true],
            P::CAMPAIGNS_MANAGE => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => false],
            P::APPROVALS_VIEW => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => true],
            P::APPROVALS_REQUEST => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => false],
            P::APPROVALS_APPROVE => [P::SUPERADMIN => true, P::OPERATOR => true, P::AUDITOR => false],
        ];
        foreach ($matrix as $permission => $byRole) {
            foreach ($byRole as $role => $allowed) {
                $cells["$role · $permission"] = [$role, $permission, $allowed];
            }
        }

        return $cells;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cells')]
    public function test_cada_celda_de_la_matriz(string $role, string $permission, bool $allowed): void
    {
        $this->assertSame($allowed, P::allows($role, $permission));
    }

    public function test_la_tabla_de_pruebas_cubre_todos_los_permisos_y_roles(): void
    {
        $covered = array_unique(array_map(fn (array $c): string => $c[1], self::cells()));
        sort($covered);
        $all = P::all();
        sort($all);
        $this->assertSame($all, $covered, 'un permiso nuevo sin su fila en esta prueba');
        $this->assertCount(count($all) * count(P::ROLES), self::cells());
    }

    public function test_un_rol_o_permiso_desconocido_no_tiene_nada(): void
    {
        $this->assertSame([], P::for('invitado'));
        $this->assertFalse(P::allows('superadmin', 'permiso.inexistente'));
        $this->assertFalse(P::allows('invitado', P::ACCOUNT_SELF));
    }
}
