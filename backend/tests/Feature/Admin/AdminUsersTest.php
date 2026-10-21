<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminPermissions;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Administración de usuarios (RF-41) y la matriz de permisos aplicada a cada ruta (CA-31). */
final class AdminUsersTest extends AdminTestCase
{
    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> método, ruta y cuerpo de cada ruta de usuarios */
    private function userRoutes(int $targetId): array
    {
        return [
            'listar' => ['GET', '/users', []],
            'crear' => ['POST', '/users', ['email' => 'nuevo-'.bin2hex(random_bytes(3)).'@ejemplo.com', 'name' => 'Nuevo', 'role' => 'auditor']],
            'editar' => ['PATCH', "/users/$targetId", ['name' => 'Otro nombre']],
            'reset 2FA' => ['POST', "/users/$targetId/reset-mfa", []],
            'reset contraseña' => ['POST', "/users/$targetId/reset-password", []],
        ];
    }

    public function test_cada_ruta_de_usuarios_solo_la_usa_el_superadmin(): void
    {
        $target = $this->makeAdmin('auditor');
        foreach ([AdminPermissions::SUPERADMIN => true, AdminPermissions::OPERATOR => false, AdminPermissions::AUDITOR => false] as $role => $allowed) {
            $actor = $this->makeAdmin($role);
            $this->login($actor);
            foreach ($this->userRoutes($target['id']) as $label => [$method, $path, $body]) {
                $r = $this->api($method, $path, $body);
                if ($allowed) {
                    $this->assertContains($r->status(), [200, 201, 204], "$role · $label dio ".$r->status());
                } else {
                    $this->assertSame(403, $r->status(), "$role · $label debía ser 403");
                    $this->assertSame('forbidden', $r->json('error.code'));
                }
            }
            $this->api('POST', '/auth/logout');
        }
    }

    public function test_sin_sesion_ninguna_ruta_de_usuarios_responde(): void
    {
        $target = $this->makeAdmin('auditor');
        foreach ($this->userRoutes($target['id']) as $label => [$method, $path, $body]) {
            $this->api($method, $path, $body)->assertUnauthorized();
        }
    }

    public function test_crear_un_usuario_devuelve_la_contrasena_temporal_una_sola_vez_y_no_se_guarda_en_claro(): void
    {
        $super = $this->makeAdmin('superadmin');
        $this->login($super);

        $r = $this->api('POST', '/users', ['email' => 'Nuevo@Ejemplo.com', 'name' => 'Nuevo', 'role' => 'operator'])->assertCreated();
        $temp = $r->json('tempPassword');
        $this->assertSame(21, strlen($temp));

        $row = $this->row($r->json('id'));
        $this->assertSame('nuevo@ejemplo.com', $row['email']);
        $this->assertTrue((bool) $row['must_change_password']);
        $this->assertNotSame($temp, $row['password_hash']);
        $this->assertTrue(Hash::check($temp, $row['password_hash']));

        $list = $this->getJson(self::PREFIX.'/users')->assertOk();
        $this->assertStringNotContainsString($temp, $list->getContent());
        $this->assertStringNotContainsString('password_hash', $list->getContent());
        $this->assertStringNotContainsString('totp_secret', $list->getContent());
    }

    public function test_la_cuenta_nueva_entra_con_la_temporal_y_se_le_pide_cambiarla(): void
    {
        $super = $this->makeAdmin('superadmin');
        $this->login($super);
        $r = $this->api('POST', '/users', ['email' => 'nuevo@ejemplo.com', 'name' => 'Nuevo', 'role' => 'operator'])->assertCreated();
        $this->api('POST', '/auth/logout');

        $this->api('POST', '/auth/login', ['email' => 'nuevo@ejemplo.com', 'password' => $r->json('tempPassword')])->assertOk()->assertExactJson(['next' => 'password']);
    }

    public function test_validaciones_de_alta(): void
    {
        $super = $this->makeAdmin('superadmin', 'jefe@ejemplo.com');
        $this->login($super);
        $bad = [
            'sin campos' => [],
            'correo inválido' => ['email' => 'no-es-correo', 'name' => 'X', 'role' => 'auditor'],
            'nombre vacío' => ['email' => 'a@b.co', 'name' => '  ', 'role' => 'auditor'],
            'rol inexistente' => ['email' => 'a@b.co', 'name' => 'X', 'role' => 'dios'],
            'correo repetido' => ['email' => 'JEFE@ejemplo.com', 'name' => 'X', 'role' => 'auditor'],
            'tipos erróneos' => ['email' => ['a'], 'name' => 'X', 'role' => 'auditor'],
        ];
        foreach ($bad as $label => $body) {
            $this->api('POST', '/users', $body)->assertStatus(422)->assertJsonPath('error.code', 'validationFailed');
        }
        $this->assertSame(1, DB::table('admin_users')->count(), 'ninguna alta inválida dejó rastro');
    }

    public function test_cambiar_rol_o_desactivar_cierra_las_sesiones_del_usuario(): void
    {
        $super = $this->makeAdmin('superadmin');
        $target = $this->makeAdmin('operator');
        $other = $this->makeAdmin('auditor');
        foreach ([['s-target', $target['id']], ['s-other', $other['id']]] as [$sid, $uid]) {
            DB::insert('INSERT INTO sessions (id, user_id, payload, last_activity) VALUES (?, ?, ?, ?)', [$sid, $uid, 'x', time()]);
        }
        $this->login($super);

        $this->api('PATCH', "/users/{$target['id']}", ['role' => 'auditor'])->assertNoContent();
        $this->assertSame(0, DB::table('sessions')->where('id', 's-target')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 's-other')->count());
        $this->assertSame('auditor', $this->row($target['id'])['role']);

        DB::insert('INSERT INTO sessions (id, user_id, payload, last_activity) VALUES (?, ?, ?, ?)', ['s-target-2', $target['id'], 'x', time()]);
        $this->api('PATCH', "/users/{$target['id']}", ['active' => false])->assertNoContent();
        $this->assertSame(0, DB::table('sessions')->where('id', 's-target-2')->count());
        $this->api('POST', '/auth/logout');
        $this->api('POST', '/auth/login', ['email' => $target['email'], 'password' => $target['password']])->assertUnauthorized();
    }

    public function test_editar_valida_tipos_y_usuarios_inexistentes(): void
    {
        $super = $this->makeAdmin('superadmin');
        $this->login($super);
        $this->api('PATCH', '/users/'.$super['id'], ['active' => 'si'])->assertStatus(422);
        $this->api('PATCH', '/users/'.$super['id'], ['role' => 7])->assertStatus(422);
        $this->api('PATCH', '/users/'.$super['id'], ['role' => 'dios'])->assertStatus(422);
        $this->api('PATCH', '/users/999999', ['name' => 'X'])->assertStatus(422);
        $this->api('POST', '/users/999999/reset-mfa')->assertNotFound();
        $this->api('POST', '/users/999999/reset-password')->assertNotFound();
        $this->api('PATCH', '/users/abc', ['name' => 'X'])->assertNotFound(); // la ruta exige un número
    }

    public function test_no_se_puede_dejar_al_sistema_sin_superadmin(): void
    {
        $only = $this->makeAdmin('superadmin');
        $this->login($only);
        $this->api('PATCH', "/users/{$only['id']}", ['active' => false])->assertStatus(409)->assertJsonPath('error.code', 'lastSuperadmin');
        $this->api('PATCH', "/users/{$only['id']}", ['role' => 'operator'])->assertStatus(409);
        $this->assertTrue((bool) $this->row($only['id'])['active']);
        $this->assertSame('superadmin', $this->row($only['id'])['role']);
        $this->getJson(self::PREFIX.'/users')->assertOk(); // nada cambió: la sesión sigue
    }

    public function test_restablecer_el_2fa_lo_quita_y_obliga_a_enrolarlo_de_nuevo(): void
    {
        $super = $this->makeAdmin('superadmin');
        $target = $this->makeAdmin('operator');
        DB::insert('INSERT INTO sessions (id, user_id, payload, last_activity) VALUES (?, ?, ?, ?)', ['s-t', $target['id'], 'x', time()]);
        $this->login($super);

        $this->api('POST', "/users/{$target['id']}/reset-mfa")->assertNoContent();
        $row = $this->row($target['id']);
        $this->assertNull($row['totp_confirmed_at']);
        $this->assertNull($row['totp_secret_enc']);
        $this->assertSame('[]', $row['recovery_codes']);
        $this->assertSame(0, DB::table('sessions')->where('id', 's-t')->count());
        $this->api('POST', '/auth/logout');

        $this->api('POST', '/auth/login', ['email' => $target['email'], 'password' => $target['password']])->assertOk()->assertExactJson(['next' => 'enroll']);
    }

    public function test_restablecer_la_contrasena_da_una_temporal_levanta_el_bloqueo_y_cierra_sesiones(): void
    {
        $super = $this->makeAdmin('superadmin');
        $target = $this->makeAdmin('operator');
        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', '/auth/login', ['email' => $target['email'], 'password' => 'mala'], csrf: true);
        }
        $this->assertNotNull($this->row($target['id'])['locked_until']);
        $this->login($super);

        $temp = $this->api('POST', "/users/{$target['id']}/reset-password")->assertOk()->json('tempPassword');
        $row = $this->row($target['id']);
        $this->assertNull($row['locked_until']);
        $this->assertSame(0, (int) $row['failed_attempts']);
        $this->assertTrue((bool) $row['must_change_password']);
        $this->assertFalse(Hash::check($target['password'], $row['password_hash']), 'la contraseña anterior ya no sirve');
        $this->assertTrue(Hash::check($temp, $row['password_hash']));
    }

    public function test_todo_acto_de_administracion_queda_en_la_bitacora_con_su_actor_y_sin_secretos(): void
    {
        $super = $this->makeAdmin('superadmin');
        $this->login($super);
        $created = $this->api('POST', '/users', ['email' => 'nuevo@ejemplo.com', 'name' => 'Nuevo', 'role' => 'operator'])->assertCreated();
        $id = $created->json('id');
        $this->api('PATCH', "/users/$id", ['role' => 'auditor'])->assertNoContent();
        $this->api('POST', "/users/$id/reset-mfa")->assertNoContent();
        $temp = $this->api('POST', "/users/$id/reset-password")->json('tempPassword');

        $actions = DB::table('audit_log')->where('actor', 'admin:'.$super['id'])->pluck('action')->all();
        foreach (['admin.user_created', 'admin.user_updated', 'admin.mfa_reset', 'admin.password_reset'] as $expected) {
            $this->assertContains($expected, $actions);
        }
        $dump = json_encode(DB::select('SELECT actor, action, subject_id, data::text FROM audit_log'));
        foreach ([$created->json('tempPassword'), $temp, 'nuevo@ejemplo.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertNull((new AuditLog)->verifyChain());
    }
}
