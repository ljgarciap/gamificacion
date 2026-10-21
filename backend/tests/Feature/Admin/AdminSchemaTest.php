<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;

/** Las invariantes de las cuentas viven en la base (como en SPEC-002): la capa PHP solo da errores claros. */
final class AdminSchemaTest extends AdminTestCase
{
    public function test_el_rol_solo_puede_ser_uno_de_los_tres(): void
    {
        $this->assertDbRejects(fn () => DB::insert("INSERT INTO admin_users (email, name, role, password_hash) VALUES ('a@b.co', 'A', 'dios', 'x')"), 'admin_users_role_check');
    }

    public function test_el_correo_va_en_minusculas_y_sin_repetirse(): void
    {
        $this->assertDbRejects(fn () => DB::insert("INSERT INTO admin_users (email, name, role, password_hash) VALUES ('Ana@B.co', 'A', 'auditor', 'x')"), 'admin_users_email_lower');
        DB::insert("INSERT INTO admin_users (email, name, role, password_hash) VALUES ('ana@b.co', 'A', 'auditor', 'x')");
        $this->assertDbRejects(fn () => DB::insert("INSERT INTO admin_users (email, name, role, password_hash) VALUES ('ana@b.co', 'B', 'auditor', 'x')"), 'admin_users_email_unique');
    }

    public function test_un_totp_confirmado_siempre_tiene_secreto(): void
    {
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO admin_users (email, name, role, password_hash, totp_confirmed_at) VALUES ('c@d.co', 'C', 'auditor', 'x', now())"),
            'admin_users_totp_has_secret',
        );
    }

    public function test_no_se_puede_quitar_al_ultimo_superadmin_activo(): void
    {
        $only = $this->makeAdmin('superadmin');
        $this->assertDbRejects(fn () => DB::update("UPDATE admin_users SET active = false WHERE id = ?", [$only['id']]), 'al menos un superadmin');
        $this->assertDbRejects(fn () => DB::update("UPDATE admin_users SET role = 'operator' WHERE id = ?", [$only['id']]), 'al menos un superadmin');
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM admin_users WHERE id = ?', [$only['id']]), 'al menos un superadmin');
    }

    public function test_con_otro_superadmin_activo_si_se_puede(): void
    {
        $a = $this->makeAdmin('superadmin');
        $b = $this->makeAdmin('superadmin');
        DB::update('UPDATE admin_users SET active = false WHERE id = ?', [$a['id']]);
        $this->assertFalse((bool) $this->row($a['id'])['active']);
        // Ahora el único activo es $b: no se puede tocar.
        $this->assertDbRejects(fn () => DB::update("UPDATE admin_users SET role = 'auditor' WHERE id = ?", [$b['id']]), 'al menos un superadmin');
    }

    public function test_un_superadmin_desactivado_no_cuenta_como_respaldo(): void
    {
        $a = $this->makeAdmin('superadmin');
        $b = $this->makeAdmin('superadmin');
        DB::update('UPDATE admin_users SET active = false WHERE id = ?', [$b['id']]);
        $this->assertDbRejects(fn () => DB::update('UPDATE admin_users SET active = false WHERE id = ?', [$a['id']]), 'al menos un superadmin');
    }
}
