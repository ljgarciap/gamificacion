<?php

namespace Tests\Feature\Admin;

use App\Domain\Demo\DemoAccounts;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoRuns;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoSwitch;
use App\Mail\DemoLoginCodeMail;
use App\Mail\DemoTestMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\HostTokens;

/** Cuentas de la muestra desde el panel (SPEC-006A M7; CA-16, CA-17, CA-18, D27, D28): listado enmascarado, borrado, retención, reinicio con elección y correo de prueba. */
final class DemoAccountsAdminTest extends AdminTestCase
{
    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure(['standalone-dev' => ['alg' => 'HS256', 'key' => base64_encode(HostTokens::SECRET)]]);
        config(['gamification.demo.allowed' => true]);
        Mail::fake();
    }

    /** @return array<string, mixed> */
    private function config(array $accounts = []): array
    {
        $c = DemoConfig::defaults();
        $c['user']['initialBalance'] = 1000;
        $c['mix'] = ['roulette' => ['prizes' => [['type' => 'money', 'amount' => 5000, 'count' => 4]], 'noPrizeCount' => 16]];
        $c['accounts'] = array_replace($c['accounts'], $accounts);

        return DemoConfig::validate($c);
    }

    /**
     * Compra con el token de la muestra. `AdminTestCase` fija un reloj de pruebas en el pasado, pero el emisor firma con la hora real: para
     * USAR el token se vuelve un momento al reloj real (el panel no interviene en esta llamada).
     */
    private function buy(array $session, string $key = 'compra-0001'): void
    {
        $fixed = Carbon::getTestNow();
        Carbon::setTestNow();
        try {
            $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-50', 'quantity' => 1]]], ['Authorization' => 'Bearer '.$session['token'], 'Idempotency-Key' => $key])->assertOk();
        } finally {
            Carbon::setTestNow($fixed);
        }
    }

    private function superadmin(): void
    {
        $this->login($this->makeAdmin('superadmin'));
    }

    /** Muestra encendida y dos personas con cuenta. @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function armWithTwoPeople(array $accounts = []): array
    {
        $cfg = $this->config($accounts);
        $switch = new DemoSwitch;
        $switch->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $switch->enable('test');
        $out = [];
        foreach (['ana.perez@example.com', 'beto@example.com'] as $email) {
            DB::delete('DELETE FROM demo_mail_log');
            $this->postJson('/v1/demo/auth/request', ['email' => $email])->assertOk();
            $out[] = $this->postJson('/v1/demo/auth/verify', ['email' => $email, 'code' => Mail::sent(DemoLoginCodeMail::class)->last()->code])->assertOk()->json();
        }

        return $out;
    }

    public function test_solo_el_superadmin_ve_y_administra_las_cuentas(): void
    {
        $this->assertSame(401, $this->api('GET', '/demo/accounts')->status());
        foreach (['operator', 'auditor'] as $role) {
            $this->login($this->makeAdmin($role));
            foreach ([['GET', '/demo/accounts'], ['DELETE', '/demo/accounts/'.\Illuminate\Support\Str::uuid()], ['POST', '/demo/mail/test']] as [$m, $p]) {
                $this->assertSame(403, $this->api($m, $p, [])->status(), "$role $m $p");
            }
            $this->api('POST', '/auth/logout');
        }
    }

    public function test_el_listado_muestra_el_correo_enmascarado_el_saldo_y_las_fechas(): void
    {
        [$ana] = $this->armWithTwoPeople();
        $this->buy($ana);
        $this->superadmin(); // después: iniciar sesión adelanta el reloj de las pruebas y el token de la muestra dejaría de verificarse

        $r = $this->api('GET', '/demo/accounts');
        $this->assertSame(200, $r->status());
        $this->assertSame(2, $r->json('total'));
        $items = collect($r->json('items'))->keyBy('email');
        $this->assertEqualsCanonicalizing(['a***@example.com', 'b***@example.com'], $items->keys()->all());
        $this->assertSame(6000, $items['a***@example.com']['balance']);
        $this->assertSame(1000, $items['b***@example.com']['balance']);
        $this->assertStringNotContainsString('ana.perez', $r->getContent());
        $this->assertStringNotContainsString('beto@', $r->getContent());
    }

    public function test_el_estado_trae_los_totales_y_como_salen_los_correos_sin_credenciales(): void
    {
        $this->superadmin();
        $this->armWithTwoPeople();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit', 'mail.mailers.smtp.port' => 1025, 'mail.mailers.smtp.username' => 'usuario-secreto', 'mail.mailers.smtp.password' => 'clave-secreta']);
        $r = $this->api('GET', '/demo');
        $this->assertSame(['total' => 2, 'active7d' => 2], $r->json('accounts'));
        $this->assertSame(['mailer' => 'smtp', 'host' => 'mailpit', 'port' => 1025], array_intersect_key($r->json('mail'), array_flip(['mailer', 'host', 'port'])));
        $this->assertStringNotContainsString('usuario-secreto', $r->getContent());
        $this->assertStringNotContainsString('clave-secreta', $r->getContent());
        $this->assertSame(DemoConfig::MAX_RETENTION_DAYS, $r->json('limits.maxRetentionDays'));
    }

    public function test_borrar_una_cuenta_la_quita_deja_rastro_sin_correo_y_el_saldo_queda_inalcanzable(): void
    {
        $this->superadmin();
        [$ana, $beto] = $this->armWithTwoPeople();
        $id = collect($this->api('GET', '/demo/accounts')->json('items'))->firstWhere('email', 'a***@example.com')['id'];

        $this->assertSame(204, $this->api('DELETE', "/demo/accounts/$id")->status());
        $this->assertSame(1, $this->api('GET', '/demo/accounts')->json('total'));
        $this->assertSame(404, $this->api('DELETE', "/demo/accounts/$id")->status(), 'ya no existe');
        $this->assertSame(404, $this->api('DELETE', '/demo/accounts/no-es-un-id')->status());
        $this->postJson('/v1/demo/session', ['resume' => $ana['subject']])->assertStatus(401);
        $this->postJson('/v1/demo/session', ['resume' => $beto['subject']])->assertOk();

        $entry = DB::selectOne("SELECT actor, data FROM audit_log WHERE action = 'demo.account_deleted'");
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('ana', $entry->data);
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM audit_log WHERE data::text ILIKE '%example.com%'")->n, 'la bitácora no guarda correos');
    }

    public function test_el_correo_de_prueba_se_envia_y_queda_en_la_bitacora_enmascarado(): void
    {
        $this->superadmin();
        $this->assertSame(204, $this->api('POST', '/demo/mail/test', ['to' => 'Quien@Example.com'])->status());
        Mail::assertSent(DemoTestMail::class, fn ($m) => $m->hasTo('quien@example.com'));
        $entry = DB::selectOne("SELECT data FROM audit_log WHERE action = 'demo.mail_test'");
        $this->assertSame(['to' => 'q***@example.com'], json_decode($entry->data, true));

        foreach (['no-es-correo', "a@example.com\r\nBcc: x@y.com", '', null] as $bad) {
            $this->assertSame(422, $this->api('POST', '/demo/mail/test', ['to' => $bad])->status());
        }
        Mail::assertSent(DemoTestMail::class, 1);
    }

    public function test_si_el_servidor_de_correo_falla_el_panel_lo_dice(): void
    {
        $this->superadmin();
        Mail::swap(new class
        {
            public function to($u): object
            {
                return new class
                {
                    public function send($m): void
                    {
                        throw new \RuntimeException('conexión rechazada con la clave secreta');
                    }
                };
            }
        });
        $r = $this->api('POST', '/demo/mail/test', ['to' => 'a@example.com']);
        $this->assertSame(503, $r->status());
        $this->assertSame('mailUnavailable', $r->json('error.code'));
        $this->assertStringNotContainsString('secreta', $r->getContent());
    }

    // --- Reiniciar con elección (D27, CA-16) ----------------------------------------------------------------------------

    public function test_reiniciar_exige_elegir_que_hacer_con_las_cuentas(): void
    {
        $this->superadmin();
        $this->assertSame(200, $this->api('PUT', '/demo/config', $this->config())->status());
        foreach ([[], ['accounts' => null], ['accounts' => 'borrar'], ['accounts' => true]] as $body) {
            $r = $this->api('POST', '/demo/reset', $body);
            $this->assertSame(422, $r->status(), json_encode($body));
        }
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_runs')->n, 'sin elección no se pidió nada');
        $this->assertSame(202, $this->api('POST', '/demo/reset', ['accounts' => 'keep'])->status());
    }

    public function test_reiniciar_conservando_deja_cuentas_y_saldos_y_retira_los_codigos_viejos(): void
    {
        [$ana] = $this->armWithTwoPeople();
        $this->buy($ana);
        $this->superadmin();

        $this->assertSame(202, $this->api('POST', '/demo/reset', ['accounts' => 'keep'])->status());
        $this->artisan('gamification:demo:process')->assertSuccessful();

        $this->assertSame(2, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n);
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned'")->n, 'los códigos viejos quedaron retirados');
        $this->assertSame(20, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n);
        $porCorreo = collect($this->api('GET', '/demo/accounts')->json('items'))->keyBy('email');
        $this->assertSame(6000, $porCorreo['a***@example.com']['balance'], 'conservar las cuentas conserva el saldo acumulado');
        $this->assertSame(1000, $porCorreo['b***@example.com']['balance']);
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM audit_log WHERE action = 'demo.accounts_deleted_all'")->n);
    }

    public function test_reiniciar_borrando_no_deja_ninguna_cuenta_ni_forma_de_llegar_a_sus_saldos(): void
    {
        $this->superadmin();
        [$ana, $beto] = $this->armWithTwoPeople();
        $this->assertSame(202, $this->api('POST', '/demo/reset', ['accounts' => 'delete'])->status());
        $this->artisan('gamification:demo:process')->assertSuccessful();

        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n);
        $this->assertSame(0, $this->api('GET', '/demo/accounts')->json('total'));
        (new DemoSwitch)->enable('test'); // el reinicio la apagó: con la muestra encendida de nuevo, esos sujetos ya no existen
        foreach ([$ana, $beto] as $s) {
            $this->postJson('/v1/demo/session', ['resume' => $s['subject']])->assertStatus(401);
        }
        $entry = DB::selectOne("SELECT data FROM audit_log WHERE action = 'demo.accounts_deleted_all'");
        $this->assertSame(['count' => 2], json_decode($entry->data, true));
        // La elección quedó en la bitácora junto al pedido.
        $pedido = DB::selectOne("SELECT data FROM audit_log WHERE action = 'demo.run_requested' ORDER BY id DESC LIMIT 1");
        $this->assertSame('delete', json_decode($pedido->data, true)['accounts']);
    }

    // --- Retención (D28, CA-17) -----------------------------------------------------------------------------------------

    public function test_la_retencion_borra_solo_las_cuentas_sin_ingreso_en_el_plazo_del_panel(): void
    {
        $this->superadmin();
        [$ana] = $this->armWithTwoPeople(['retentionDays' => 10]);
        DB::update("UPDATE demo_accounts SET last_login_at = now() - interval '11 days' WHERE subject = ?", [$ana['subject']]);

        $this->artisan('gamification:demo:process')->assertSuccessful();
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n, 'la de ayer sigue');
        $this->postJson('/v1/demo/session', ['resume' => $ana['subject']])->assertStatus(401);
        $entry = DB::selectOne("SELECT actor, data FROM audit_log WHERE action = 'demo.accounts_purged'");
        $this->assertSame(['count' => 1, 'retentionDays' => 10], json_decode($entry->data, true));
        $this->assertSame('system', $entry->actor);
    }

    public function test_ingresar_renueva_el_plazo_de_la_retencion(): void
    {
        $this->superadmin();
        [$ana] = $this->armWithTwoPeople(['retentionDays' => 10]);
        DB::update("UPDATE demo_accounts SET last_login_at = now() - interval '9 days'");
        DB::delete('DELETE FROM demo_mail_log');
        $this->postJson('/v1/demo/auth/request', ['email' => 'ana.perez@example.com'])->assertOk();
        $this->postJson('/v1/demo/auth/verify', ['email' => 'ana.perez@example.com', 'code' => Mail::sent(DemoLoginCodeMail::class)->last()->code])->assertOk();
        DB::update("UPDATE demo_accounts SET last_login_at = last_login_at WHERE true");
        $viejo = (float) DB::selectOne("SELECT extract(epoch FROM now() - last_login_at) / 86400 AS d FROM demo_accounts WHERE subject = ?", [$ana['subject']])->d;
        $this->assertLessThan(1, $viejo, 'el ingreso reinició el contador');
        $this->artisan('gamification:demo:process')->assertSuccessful();
        $this->assertSame(2, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n);
    }

    public function test_el_proceso_purga_codigos_y_registro_de_envios_vencidos(): void
    {
        $this->superadmin();
        $this->armWithTwoPeople();
        DB::update("UPDATE demo_login_codes SET expires_at = now() - interval '2 days'");
        DB::update("UPDATE demo_mail_log SET created_at = now() - interval '3 hours'");
        $this->artisan('gamification:demo:process')->assertSuccessful();
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_login_codes')->n);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_mail_log')->n);
    }

    public function test_guardar_la_configuracion_con_cuentas_y_sin_ellas(): void
    {
        $this->superadmin();
        $c = $this->config(['retentionDays' => 45, 'codeTtlMinutes' => 15, 'maxAccounts' => 99]);
        $this->assertSame(200, $this->api('PUT', '/demo/config', $c)->status());
        $this->assertEquals(['retentionDays' => 45, 'codeTtlMinutes' => 15, 'maxAccounts' => 99], $this->api('GET', '/demo')->json('config.accounts'));
        // Un cliente anterior que no manda `accounts` no rompe nada: queda con los valores por defecto.
        unset($c['accounts']);
        $this->assertSame(200, $this->api('PUT', '/demo/config', $c)->status());
        $this->assertEquals(DemoConfig::defaultAccounts(), $this->api('GET', '/demo')->json('config.accounts'));
        // Fuera de los techos: 422 y no se guarda.
        $c['accounts'] = ['retentionDays' => 9999, 'codeTtlMinutes' => 10, 'maxAccounts' => 10];
        $this->assertSame(422, $this->api('PUT', '/demo/config', $c)->status());
    }
}
