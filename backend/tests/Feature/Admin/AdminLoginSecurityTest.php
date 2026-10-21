<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Amenazas T1–T6 de docs/amenazas-spec-003.md: fuerza bruta, oráculos, CSRF. */
final class AdminLoginSecurityTest extends AdminTestCase
{
    private function lockedSeconds(int $id): int
    {
        $until = $this->row($id)['locked_until'];

        return $until === null ? 0 : max(0, Carbon::parse($until)->getTimestamp() - Carbon::now()->getTimestamp());
    }

    // --- Sin oráculo ----------------------------------------------------------------------------------------------------

    public function test_todo_fallo_de_credenciales_es_indistinguible(): void
    {
        $ok = $this->makeAdmin('operator');
        $inactive = $this->makeAdmin('auditor');
        DB::update('UPDATE admin_users SET active = false WHERE id = ?', [$inactive['id']]);
        $locked = $this->makeAdmin('auditor');
        DB::update("UPDATE admin_users SET locked_until = ? WHERE id = ?", [Carbon::now()->addHour()->format('Y-m-d H:i:s.uP'), $locked['id']]);

        $cases = [
            'correo inexistente' => ['nadie@ejemplo.com', 'cualquiera'],
            'contraseña errónea' => [$ok['email'], 'incorrecta-del-todo'],
            'cuenta desactivada con la contraseña correcta' => [$inactive['email'], $inactive['password']],
            'cuenta bloqueada con la contraseña correcta' => [$locked['email'], $locked['password']],
        ];
        $seen = [];
        foreach ($cases as $label => [$email, $password]) {
            $r = $this->api('POST', '/auth/login', ['email' => $email, 'password' => $password]);
            $seen[$label] = [$r->status(), $r->json()];
        }
        $this->assertCount(1, array_unique(array_map('serialize', $seen)), 'cada causa responde exactamente igual: '.json_encode($seen));
        $this->assertSame(401, array_values($seen)[0][0]);
    }

    public function test_un_correo_inexistente_pasa_por_el_mismo_camino_costoso_que_uno_real(): void
    {
        // Para que tarde lo mismo, SIEMPRE se compara contra un hash: el real o el señuelo (que se calcula una vez y se guarda).
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('gamif:admin:decoy-hash'));
        $real = $this->makeAdmin('operator');
        $this->api('POST', '/auth/login', ['email' => $real['email'], 'password' => 'mala'])->assertUnauthorized();
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('gamif:admin:decoy-hash'), 'una cuenta real se compara con su hash, no con el señuelo');

        $this->api('POST', '/auth/login', ['email' => 'nadie@ejemplo.com', 'password' => 'mala'])->assertUnauthorized();
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('gamif:admin:decoy-hash'), 'una cuenta inexistente se compara con el señuelo');
        $decoy = \Illuminate\Support\Facades\Cache::get('gamif:admin:decoy-hash');
        $this->api('POST', '/auth/login', ['email' => 'otro-nadie@ejemplo.com', 'password' => 'mala'])->assertUnauthorized();
        $this->assertSame($decoy, \Illuminate\Support\Facades\Cache::get('gamif:admin:decoy-hash'), 'el señuelo no se recalcula en cada petición');
    }

    public function test_el_correo_se_normaliza_y_valida_su_tipo(): void
    {
        $admin = $this->makeAdmin('operator', 'ana@ejemplo.com');
        $this->api('POST', '/auth/login', ['email' => '  ANA@Ejemplo.COM ', 'password' => $admin['password']])->assertOk();
        foreach ([['email' => ['a'], 'password' => 'x'], ['email' => 'a@b.co', 'password' => ['x']], ['email' => '', 'password' => ''], []] as $bad) {
            $this->api('POST', '/auth/login', $bad)->assertUnauthorized()->assertJsonPath('error.code', 'invalidCredentials');
        }
        $this->api('POST', '/auth/login', ['email' => 'a@b.co', 'password' => str_repeat('x', 5000)])->assertUnauthorized();
    }

    // --- Bloqueo por cuenta ---------------------------------------------------------------------------------------------

    public function test_cinco_contrasenas_malas_bloquean_la_cuenta_aunque_despues_acierte(): void
    {
        $admin = $this->makeAdmin('operator');
        for ($i = 0; $i < 4; $i++) {
            $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => 'mala-'.$i])->assertUnauthorized();
        }
        $this->assertSame(0, $this->lockedSeconds($admin['id']));
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => 'mala-4'])->assertUnauthorized();
        $this->assertSame(60, $this->lockedSeconds($admin['id']), 'primer bloqueo: la base de 60 s');

        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertUnauthorized(); // correcta, pero bloqueada
        $this->advance(61);
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk();
    }

    public function test_el_bloqueo_se_duplica_en_cada_nivel_y_tiene_tope(): void
    {
        $admin = $this->makeAdmin('operator');
        $expected = [60, 120, 240, 480, 960, 1920, 3600, 3600]; // 60·2^(n-1), con tope de 3600
        foreach ($expected as $level => $seconds) {
            // Cada nivel desde una IP distinta: lo que se prueba aquí es el bloqueo de la CUENTA, no el de la IP.
            $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.'.($level + 1)]);
            for ($i = 0; $i < 5; $i++) {
                $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => 'mala'])->assertUnauthorized();
            }
            $this->assertSame($seconds, $this->lockedSeconds($admin['id']), 'nivel '.($level + 1));
            $this->advance($seconds + 1);
        }
    }

    public function test_los_fallos_del_segundo_factor_tambien_bloquean(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', '/auth/mfa', ['code' => sprintf('%06d', $i)])->assertUnauthorized()->assertJsonPath('error.code', 'invalidCode');
        }
        $this->assertGreaterThan(0, $this->lockedSeconds($admin['id']));
        // Con la cuenta bloqueada, ni el código correcto sirve.
        $this->api('POST', '/auth/mfa', ['code' => $this->nextCodeKeepingLock($admin['secret'])])->assertUnauthorized();
    }

    /** Un código válido SIN avanzar el reloj más allá del bloqueo. */
    private function nextCodeKeepingLock(string $secret): string
    {
        return \App\Domain\Admin\Totp::code($secret, \App\Domain\Admin\Totp::step(Carbon::now()->getTimestamp()));
    }

    public function test_acertar_el_segundo_factor_reinicia_el_contador_de_fallos(): void
    {
        $admin = $this->makeAdmin('operator');
        for ($i = 0; $i < 3; $i++) {
            $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => 'mala'])->assertUnauthorized();
        }
        $this->assertSame(3, (int) $this->row($admin['id'])['failed_attempts']);
        $this->login($admin);
        $this->assertSame(0, (int) $this->row($admin['id'])['failed_attempts']);
        $this->assertNull($this->row($admin['id'])['locked_until']);
    }

    public function test_solo_la_contrasena_correcta_no_reinicia_el_contador(): void
    {
        $admin = $this->makeAdmin('operator');
        for ($i = 0; $i < 3; $i++) {
            $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => 'mala'])->assertUnauthorized();
        }
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk(); // sin segundo factor
        $this->assertSame(3, (int) $this->row($admin['id'])['failed_attempts'], 'quien tiene la contraseña pero no el 2FA no borra el rastro');
    }

    public function test_el_mismo_codigo_no_se_acepta_dos_veces_a_nivel_de_servicio(): void
    {
        $admin = $this->makeAdmin('operator');
        $user = $this->accounts->find($admin['id']);
        $code = $this->nextCode($admin['secret']);
        $this->accounts->verifyMfa($user, $code, Carbon::now()->getTimestamp(), null); // la primera vale
        $this->expectException(\App\Domain\Admin\LoginFailed::class);
        $this->accounts->verifyMfa($user, $code, Carbon::now()->getTimestamp(), null);
    }

    // --- Límite por IP --------------------------------------------------------------------------------------------------

    public function test_veinte_fallos_desde_una_ip_la_bloquean_aunque_cambie_de_correo(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->api('POST', '/auth/login', ['email' => "inexistente-$i@ejemplo.com", 'password' => 'x'])->assertUnauthorized();
        }
        $blocked = $this->api('POST', '/auth/login', ['email' => 'otro@ejemplo.com', 'password' => 'x'])->assertStatus(429);
        $this->assertSame('rateLimited', $blocked->json('error.code'));
        $this->assertSame(900, $blocked->json('error.retryAfter'));

        $real = $this->makeAdmin('operator');
        $this->api('POST', '/auth/login', ['email' => $real['email'], 'password' => $real['password']])->assertStatus(429); // ni con credenciales buenas
        $this->advance(901);
        $this->api('POST', '/auth/login', ['email' => $real['email'], 'password' => $real['password']])->assertOk();
    }

    // --- CSRF -----------------------------------------------------------------------------------------------------------

    public function test_una_peticion_que_cambia_estado_sin_token_csrf_se_rechaza(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']], csrf: false)
            ->assertStatus(419)->assertJsonPath('error.code', 'csrfMismatch');
        $this->assertSame(0, (int) $this->row($admin['id'])['failed_attempts']);
    }

    public function test_un_token_csrf_equivocado_se_rechaza(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->json('POST', self::PREFIX.'/auth/login', ['email' => $admin['email'], 'password' => $admin['password']], ['X-CSRF-TOKEN' => 'otro-token'])
            ->assertStatus(419);
    }

    public function test_las_acciones_criticas_tampoco_pasan_sin_token_aunque_la_sesion_sea_valida(): void
    {
        $super = $this->makeAdmin('superadmin');
        $this->login($super);
        $this->api('POST', '/users', ['email' => 'x@ejemplo.com', 'name' => 'X', 'role' => 'auditor'], csrf: false)->assertStatus(419);
        $this->assertNull(DB::table('admin_users')->where('email', 'x@ejemplo.com')->first());
    }

    public function test_las_lecturas_no_exigen_token_y_la_respuesta_entrega_la_cookie_xsrf(): void
    {
        $r = $this->getJson(self::PREFIX.'/auth/csrf')->assertNoContent();
        $this->assertNotNull($r->headers->getCookies() ? collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN') : null, 'cookie XSRF-TOKEN');
    }

    public function test_las_respuestas_de_autenticacion_no_se_cachean(): void
    {
        $admin = $this->makeAdmin('operator');
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertHeader('Cache-Control', 'no-store, private');
        $this->api('POST', '/auth/login', ['email' => 'no@ejemplo.com', 'password' => 'x'])->assertHeader('Cache-Control');
    }
}
