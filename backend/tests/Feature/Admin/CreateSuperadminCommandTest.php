<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** El primer superadmin solo se crea por consola: no hay usuarios por defecto ni ruta pública de registro. */
final class CreateSuperadminCommandTest extends AdminTestCase
{
    public function test_crea_un_superadmin_con_contrasena_temporal_que_hay_que_cambiar(): void
    {
        $this->artisan('admin:create-superadmin', ['email' => 'Jefe@Ejemplo.com', '--name' => 'La Jefa'])
            ->expectsOutputToContain('Contraseña temporal: ')
            ->expectsOutputToContain('UNA sola vez')
            ->assertSuccessful();

        $row = DB::table('admin_users')->where('email', 'jefe@ejemplo.com')->first();
        $this->assertSame('superadmin', $row->role);
        $this->assertSame('La Jefa', $row->name);
        $this->assertTrue((bool) $row->must_change_password);
        $this->assertNull($row->totp_confirmed_at);
    }

    public function test_la_contrasena_impresa_es_la_que_vale_y_en_la_base_solo_esta_su_hash(): void
    {
        Artisan::call('admin:create-superadmin', ['email' => 'jefe@ejemplo.com']);
        $this->assertSame(1, preg_match('/Contraseña temporal: (\S+)/', Artisan::output(), $m), 'el comando imprime la contraseña');
        $temp = $m[1];

        $row = DB::table('admin_users')->where('email', 'jefe@ejemplo.com')->first();
        $this->assertTrue(Hash::check($temp, $row->password_hash), 'la impresa es la que vale');
        $this->assertStringNotContainsString($temp, json_encode($row), 'en la base no queda en claro');
        $this->assertStringNotContainsString($temp, json_encode(DB::select('SELECT data::text FROM audit_log')), 'ni en la bitácora');
        $this->api('POST', '/auth/login', ['email' => 'jefe@ejemplo.com', 'password' => $temp])->assertOk()->assertExactJson(['next' => 'password']);
    }

    public function test_rechaza_correos_invalidos_y_repetidos_sin_dejar_rastro(): void
    {
        $this->artisan('admin:create-superadmin', ['email' => 'no-es-correo'])->expectsOutputToContain('Correo inválido')->assertFailed();
        $this->artisan('admin:create-superadmin', ['email' => 'jefe@ejemplo.com'])->assertSuccessful();
        $this->artisan('admin:create-superadmin', ['email' => 'JEFE@ejemplo.com'])->expectsOutputToContain('Ya existe')->assertFailed();
        $this->assertSame(1, DB::table('admin_users')->count());
    }

    public function test_no_existe_ninguna_ruta_publica_de_registro(): void
    {
        foreach (['/register', '/admin/api/v1/register', '/admin/api/v1/auth/register', '/admin/api/v1/auth/signup'] as $path) {
            $this->postJson($path, ['email' => 'x@y.co', 'password' => 'x'])->assertNotFound();
        }
    }

    public function test_quien_crea_la_cuenta_queda_en_la_bitacora_como_cli(): void
    {
        $this->artisan('admin:create-superadmin', ['email' => 'jefe@ejemplo.com'])->assertSuccessful();
        $this->assertSame('cli', DB::table('audit_log')->where('action', 'admin.user_created')->value('actor'));
    }
}
