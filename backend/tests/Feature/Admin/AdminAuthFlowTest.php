<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Base32;
use App\Domain\Admin\Totp;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\HostTokens;

/** El inicio de sesión del panel de punta a punta: contraseña → cambio inicial → alta del 2FA → código. */
final class AdminAuthFlowTest extends AdminTestCase
{
    public function test_el_flujo_completo_de_una_cuenta_nueva(): void
    {
        $created = $this->accounts->create('nueva@ejemplo.com', 'Nueva', 'operator', 'test');

        $this->api('POST', '/auth/login', ['email' => 'nueva@ejemplo.com', 'password' => $created['tempPassword']])
            ->assertOk()->assertExactJson(['next' => 'password']);
        $this->api('POST', '/auth/mfa', ['code' => '123456'])->assertUnauthorized(); // todavía no toca

        $this->api('POST', '/auth/password', ['current' => $created['tempPassword'], 'new' => self::GOOD_PASSWORD])
            ->assertOk()->assertExactJson(['next' => 'enroll']);

        $enroll = $this->api('POST', '/auth/mfa/enroll')->assertOk();
        $secret = Base32::decode($enroll->json('secret'));
        $this->assertSame(20, strlen($secret));
        $this->assertStringStartsWith('otpauth://totp/', $enroll->json('uri'));
        $this->assertStringContainsString($enroll->json('secret'), $enroll->json('uri'));

        $done = $this->api('POST', '/auth/mfa/enroll/confirm', ['code' => $this->nextCode($secret)])->assertOk();
        $this->assertCount(10, $done->json('recoveryCodes'));
        $this->assertSame('operator', $done->json('user.role'));
        $this->assertSame(\App\Domain\Admin\AdminPermissions::for('operator'), $done->json('user.permissions'));

        $this->getJson(self::PREFIX.'/auth/me')->assertOk()->assertJsonPath('email', 'nueva@ejemplo.com')->assertJsonMissingPath('password_hash');

        $this->api('POST', '/auth/logout')->assertNoContent();
        $this->getJson(self::PREFIX.'/auth/me')->assertUnauthorized();
    }

    public function test_cada_etapa_solo_es_accesible_en_su_momento(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->api('POST', '/auth/mfa', ['code' => '123456'])->assertUnauthorized();
        $this->api('POST', '/auth/mfa/enroll')->assertUnauthorized();
        $this->api('POST', '/auth/password', ['current' => 'x', 'new' => 'y'])->assertUnauthorized();

        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk()->assertJson(['next' => 'mfa']);
        $this->api('POST', '/auth/mfa/enroll')->assertUnauthorized(); // ya tiene 2FA: no se puede reenrolar con solo la contraseña
        $this->getJson(self::PREFIX.'/auth/me')->assertUnauthorized(); // contraseña sola NO da acceso
        $this->getJson(self::PREFIX.'/users')->assertUnauthorized();
    }

    public function test_la_sesion_pendiente_caduca(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk();
        $this->advance((int) config('gamification.admin.pending_ttl_seconds') + 1);
        $this->api('POST', '/auth/mfa', ['code' => Totp::code($admin['secret'], Totp::step(Carbon::now()->getTimestamp()))])->assertUnauthorized();
    }

    /**
     * Fijación de sesión (T5): un atacante que conoce el id de sesión de la víctima antes de que inicie sesión no debe poder usarlo después.
     * Se prueba a nivel de controlador con el id FIJADO de antemano (el cliente de pruebas de Laravel descarta las cookies que se le pasan,
     * y sin una cookie cada petición recibe un id nuevo y la prueba no demostraría nada).
     */
    public function test_fijacion_de_sesion_el_id_que_conocia_el_atacante_no_sobrevive_al_inicio_de_sesion(): void
    {
        $admin = $this->makeAdmin('operator');
        $store = app('session.store');
        $store->setId($fixed = str_repeat('a', 40));
        $controller = app(\App\Http\Controllers\Admin\AuthController::class);
        $request = function (array $data) use ($store): \Illuminate\Http\Request {
            $r = \Illuminate\Http\Request::create('/admin/api/v1/x', 'POST', $data);
            $r->setLaravelSession($store);

            return $r;
        };

        $this->assertSame($fixed, $store->getId(), 'precondición: el id está fijado');
        $controller->login($request(['email' => $admin['email'], 'password' => $admin['password']]));
        $pending = $store->getId();
        $this->assertNotSame($fixed, $pending, 'tras la contraseña el id debe ser nuevo');

        $controller->mfa($request(['code' => $this->nextCode($admin['secret'])]));
        $this->assertNotSame($pending, $store->getId(), 'tras el segundo factor el id vuelve a cambiar');
        $this->assertNotNull($store->get('admin.mfa_verified_at'), 'y la sesión quedó autenticada');
    }

    public function test_una_sesion_autenticada_sin_segundo_factor_verificado_no_pasa(): void
    {
        // Aunque el guardia ya tenga al usuario (p. ej. un fallo en otro punto), sin `mfa_verified_at` en ESTA sesión no se entra.
        $admin = $this->makeAdmin('superadmin');
        \Illuminate\Support\Facades\Auth::guard('admin')->loginUsingId($admin['id']);
        $this->getJson(self::PREFIX.'/auth/me')->assertUnauthorized();
        $this->getJson(self::PREFIX.'/users')->assertUnauthorized();
    }

    public function test_la_cookie_de_sesion_es_httponly_y_samesite_lax(): void
    {
        $admin = $this->makeAdmin('operator');
        $response = $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie, 'la sesión viaja en una cookie');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_un_codigo_totp_no_sirve_dos_veces(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk();
        $code = $this->nextCode($admin['secret']);
        $this->api('POST', '/auth/mfa', ['code' => $code])->assertOk();
        $this->api('POST', '/auth/logout')->assertNoContent();

        // Quien observó el código intenta entrar con él dentro de su ventana de validez.
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk();
        $this->api('POST', '/auth/mfa', ['code' => $code])->assertUnauthorized()->assertJsonPath('error.code', 'invalidCode');
    }

    public function test_los_codigos_de_recuperacion_son_de_un_solo_uso_y_se_aceptan_sin_guiones_ni_mayusculas(): void
    {
        $created = $this->accounts->create('rec@ejemplo.com', 'Rec', 'auditor', 'test');
        $this->api('POST', '/auth/login', ['email' => 'rec@ejemplo.com', 'password' => $created['tempPassword']])->assertOk();
        $this->api('POST', '/auth/password', ['current' => $created['tempPassword'], 'new' => self::GOOD_PASSWORD])->assertOk();
        $secret = Base32::decode($this->api('POST', '/auth/mfa/enroll')->json('secret'));
        $codes = $this->api('POST', '/auth/mfa/enroll/confirm', ['code' => $this->nextCode($secret)])->assertOk()->json('recoveryCodes');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){3}$/', $codes[0]);
        $this->assertCount(10, array_unique($codes));
        $this->api('POST', '/auth/logout')->assertNoContent();

        $this->api('POST', '/auth/login', ['email' => 'rec@ejemplo.com', 'password' => self::GOOD_PASSWORD])->assertOk();
        $this->api('POST', '/auth/mfa', ['code' => strtolower(str_replace('-', '', $codes[0]))])->assertOk();
        $this->api('POST', '/auth/logout')->assertNoContent();

        $this->api('POST', '/auth/login', ['email' => 'rec@ejemplo.com', 'password' => self::GOOD_PASSWORD])->assertOk();
        $this->api('POST', '/auth/mfa', ['code' => $codes[0]])->assertUnauthorized(); // ya usado
        $this->api('POST', '/auth/mfa', ['code' => $codes[1]])->assertOk(); // otro, sí
        $this->assertSame(8, count(json_decode($this->row($created['id'])['recovery_codes'], true)));
    }

    public function test_las_huellas_de_recuperacion_no_dejan_los_codigos_en_claro(): void
    {
        $created = $this->accounts->create('rec2@ejemplo.com', 'Rec', 'auditor', 'test');
        $this->api('POST', '/auth/login', ['email' => 'rec2@ejemplo.com', 'password' => $created['tempPassword']])->assertOk();
        $this->api('POST', '/auth/password', ['current' => $created['tempPassword'], 'new' => self::GOOD_PASSWORD])->assertOk();
        $secret = Base32::decode($this->api('POST', '/auth/mfa/enroll')->json('secret'));
        $codes = $this->api('POST', '/auth/mfa/enroll/confirm', ['code' => $this->nextCode($secret)])->json('recoveryCodes');

        $stored = (string) $this->row($created['id'])['recovery_codes'];
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $stored);
            $this->assertStringNotContainsString(str_replace('-', '', $code), $stored);
        }
    }

    public function test_el_secreto_totp_no_queda_en_claro_en_la_base(): void
    {
        $created = $this->accounts->create('sec@ejemplo.com', 'Sec', 'auditor', 'test');
        $this->api('POST', '/auth/login', ['email' => 'sec@ejemplo.com', 'password' => $created['tempPassword']])->assertOk();
        $this->api('POST', '/auth/password', ['current' => $created['tempPassword'], 'new' => self::GOOD_PASSWORD])->assertOk();
        $secret = Base32::decode($this->api('POST', '/auth/mfa/enroll')->json('secret'));

        $hex = DB::selectOne("SELECT encode(totp_secret_enc, 'hex') h FROM admin_users WHERE id = ?", [$created['id']])->h;
        $this->assertStringNotContainsString(bin2hex($secret), $hex);
    }

    public function test_el_cambio_de_contrasena_valida_la_actual_y_la_politica(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->login($admin);

        $this->api('POST', '/auth/password', ['current' => 'incorrecta', 'new' => 'Otra-Clave-Larga-77'])->assertUnauthorized();
        $this->api('POST', '/auth/password', ['current' => $admin['password'], 'new' => 'corta'])->assertStatus(422)->assertJsonPath('error.code', 'weakPassword');
        $this->api('POST', '/auth/password', ['current' => $admin['password'], 'new' => $admin['password']])->assertStatus(422);
        $this->api('POST', '/auth/password', ['current' => $admin['password'], 'new' => 'Otra-Clave-Larga-77'])->assertOk();

        $this->api('POST', '/auth/logout')->assertNoContent();
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertUnauthorized();
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => 'Otra-Clave-Larga-77'])->assertOk();
    }

    public function test_cambiar_la_contrasena_cierra_las_otras_sesiones_pero_no_la_actual(): void
    {
        $admin = $this->makeAdmin('operator');
        $other = $this->makeAdmin('auditor');
        $now = time();
        foreach (['otra-sesion-a', 'otra-sesion-b'] as $id) {
            DB::insert('INSERT INTO sessions (id, user_id, payload, last_activity) VALUES (?, ?, ?, ?)', [$id, $admin['id'], 'x', $now]);
        }
        DB::insert('INSERT INTO sessions (id, user_id, payload, last_activity) VALUES (?, ?, ?, ?)', ['de-otro-usuario', $other['id'], 'x', $now]);

        $this->login($admin);
        $this->api('POST', '/auth/password', ['current' => $admin['password'], 'new' => 'Otra-Clave-Larga-77'])->assertOk();

        $this->assertSame(0, DB::table('sessions')->whereIn('id', ['otra-sesion-a', 'otra-sesion-b'])->count(), 'las otras sesiones del usuario se cierran');
        $this->assertSame(1, DB::table('sessions')->where('id', 'de-otro-usuario')->count(), 'las de otros usuarios no se tocan');
        $this->getJson(self::PREFIX.'/auth/me')->assertOk(); // la actual sigue viva
    }

    public function test_una_cuenta_con_cambio_de_contrasena_pendiente_no_usa_la_sesion(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->login($admin);
        DB::update('UPDATE admin_users SET must_change_password = true WHERE id = ?', [$admin['id']]);
        $this->getJson(self::PREFIX.'/auth/me')->assertUnauthorized();
    }

    public function test_la_sesion_vence_por_antiguedad_absoluta(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->login($admin);
        $this->getJson(self::PREFIX.'/auth/me')->assertOk();
        $this->advance((int) config('gamification.admin.session_absolute_hours') * 3600 + 60);
        $this->getJson(self::PREFIX.'/auth/me')->assertUnauthorized();
    }

    public function test_desactivar_o_cambiar_de_rol_surte_efecto_en_la_siguiente_peticion(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->makeAdmin('superadmin');
        $this->login($admin);
        $this->getJson(self::PREFIX.'/users')->assertForbidden();

        DB::update("UPDATE admin_users SET role = 'superadmin' WHERE id = ?", [$admin['id']]);
        $this->getJson(self::PREFIX.'/users')->assertOk();

        DB::update('UPDATE admin_users SET active = false WHERE id = ?', [$admin['id']]);
        $this->getJson(self::PREFIX.'/users')->assertUnauthorized();
    }

    public function test_un_token_de_host_no_sirve_en_el_panel_ni_la_sesion_del_panel_en_la_api_de_jugadores(): void
    {
        HostTokens::configure();
        $this->getJson(self::PREFIX.'/auth/me', HostTokens::bearer('ana'))->assertUnauthorized();
        $this->getJson(self::PREFIX.'/users', HostTokens::bearer('ana'))->assertUnauthorized();

        $admin = $this->makeAdmin('superadmin');
        $this->login($admin);
        $this->getJson('/v1/balance')->assertUnauthorized();
        $this->postJson('/v1/codes/redeem', ['code' => 'X'], ['Idempotency-Key' => 'clave-0000001'])->assertUnauthorized();
    }

    public function test_la_bitacora_registra_los_actos_sin_secretos_y_la_cadena_sigue_integra(): void
    {
        $created = $this->accounts->create('aud@ejemplo.com', 'Aud', 'operator', 'test');
        $this->api('POST', '/auth/login', ['email' => 'aud@ejemplo.com', 'password' => $created['tempPassword']])->assertOk();
        $this->api('POST', '/auth/password', ['current' => $created['tempPassword'], 'new' => self::GOOD_PASSWORD])->assertOk();
        $enroll = $this->api('POST', '/auth/mfa/enroll')->json();
        $secret = Base32::decode($enroll['secret']);
        $codes = $this->api('POST', '/auth/mfa/enroll/confirm', ['code' => $this->nextCode($secret)])->json('recoveryCodes');
        $this->api('POST', '/auth/logout');
        $this->api('POST', '/auth/login', ['email' => 'aud@ejemplo.com', 'password' => 'incorrecta-del-todo']);

        $actions = DB::table('audit_log')->orderBy('id')->pluck('action')->all();
        foreach (['admin.user_created', 'admin.password_changed', 'admin.mfa_enrolled', 'admin.login_succeeded', 'admin.login_failed'] as $expected) {
            $this->assertContains($expected, $actions);
        }
        $dump = json_encode(DB::select('SELECT actor, action, subject_id, data::text FROM audit_log'));
        foreach ([$created['tempPassword'], self::GOOD_PASSWORD, $enroll['secret'], 'aud@ejemplo.com', ...$codes] as $secretValue) {
            $this->assertStringNotContainsString($secretValue, $dump, 'la bitácora no debe llevar secretos ni el correo en claro');
        }
        $this->assertNull((new AuditLog)->verifyChain());
    }
}
