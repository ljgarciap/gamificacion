<?php

namespace Tests\Feature\Demo;

use App\Domain\Admin\AdminException;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoGrants;
use App\Domain\Demo\DemoPoolDraw;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoSwitch;
use App\Mail\DemoLoginCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\DbTestCase;
use Tests\Support\HostTokens;
use Tests\Support\PlansFixture;

/** Turnos de bienvenida y reactivación (SPEC-007 M12; CA-72, CA-73, T-D24, T-D25, T-D27): por ingreso, no por compra. */
final class DemoGrantsTest extends DbTestCase
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

    private function arm(?array $config = null): array
    {
        $cfg = $config ?? PlansFixture::config();
        $switch = new DemoSwitch;
        $switch->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $switch->enable('test');

        return $cfg;
    }

    /** Ingreso real por correo y código. @return array<string, mixed> */
    private function login(string $email = 'ana@example.com'): array
    {
        DB::delete('DELETE FROM demo_mail_log'); // sin esperas de reenvío entre ingresos de la misma persona
        $this->postJson('/v1/demo/auth/request', ['email' => $email])->assertOk();
        $r = $this->postJson('/v1/demo/auth/verify', ['email' => $email, 'code' => Mail::sent(DemoLoginCodeMail::class)->last()->code]);
        $r->assertOk();

        return $r->json();
    }

    private function codes(array $session): array
    {
        return $this->getJson('/v1/demo/account', ['Authorization' => 'Bearer '.$session['token']])->assertOk()->json('codes');
    }

    private function accountId(): string
    {
        return DB::selectOne('SELECT id FROM demo_accounts ORDER BY created_at DESC LIMIT 1')->id;
    }

    private function grants(string $kind): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n FROM demo_grants WHERE kind = ?', [$kind])->n;
    }

    // --- Bienvenida (CA-72) ----------------------------------------------------------------------------------------------

    public function test_una_cuenta_nueva_recibe_un_turno_de_bienvenida_y_reingresar_no_da_otro(): void
    {
        $this->arm();
        $first = $this->login();
        $this->assertTrue($first['isNew']);
        $this->assertSame(['plan' => 'Bienvenida', 'kind' => 'welcome'], $first['grant']);
        $codes = $this->codes($first);
        $this->assertCount(1, $codes);
        $this->assertSame('Bienvenida', $codes[0]['plan']);
        $this->assertFalse($codes[0]['played']);

        $again = $this->login();
        $this->assertFalse($again['isNew']);
        $this->assertArrayNotHasKey('grant', $again);
        $this->assertCount(1, $this->codes($again));
        $this->assertSame(1, $this->grants('welcome'));
    }

    public function test_sin_el_interruptor_de_bienvenida_la_cuenta_nueva_no_recibe_turno(): void
    {
        $this->arm(PlansFixture::config(['welcomeOnFirstLogin' => false]));
        $s = $this->login();
        $this->assertArrayNotHasKey('grant', $s);
        $this->assertSame([], $this->codes($s));
    }

    public function test_el_turno_de_bienvenida_sale_del_pool_de_bienvenida_y_no_de_otro_plan(): void
    {
        $this->arm();
        $this->login();
        $plan = DB::selectOne("SELECT plan, grant_id, order_id FROM demo_pool WHERE state = 'assigned'");
        $this->assertSame('welcome', $plan->plan);
        $this->assertNotNull($plan->grant_id);
        $this->assertNull($plan->order_id);
    }

    public function test_renovar_el_token_no_otorga_turnos(): void
    {
        $this->arm();
        $s = $this->login();
        $r = $this->postJson('/v1/demo/session', ['resume' => $s['subject']])->assertOk();
        $this->assertArrayNotHasKey('grant', $r->json());
        $this->assertSame(1, $this->grants('welcome') + $this->grants('reactivation'));
    }

    public function test_renovar_el_token_de_una_cuenta_inactiva_tampoco_otorga_el_turno(): void
    {
        $cfg = $this->arm();
        $s = $this->login();
        (new DemoGrants)->simulateInactivity($this->accountId(), 'tester', $cfg);
        $r = $this->postJson('/v1/demo/session', ['resume' => $s['subject']])->assertOk();
        $this->assertArrayNotHasKey('grant', $r->json());
        $this->assertSame(0, $this->grants('reactivation'), 'renovar el token no es ingresar');
        $this->assertSame('reactivation', $this->login()['grant']['kind'], 'el turno espera al próximo ingreso con código');
    }

    public function test_en_el_modo_anterior_no_hay_turnos_ni_campo_grant(): void
    {
        $cfg = DemoConfig::validate(DemoConfig::defaults());
        (new DemoSwitch)->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        (new DemoSwitch)->enable('test');
        $s = $this->login();
        $this->assertArrayNotHasKey('grant', $s);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_grants')->n);
    }

    // --- Reactivación (CA-73) ---------------------------------------------------------------------------------------------

    public function test_ingresar_antes_de_los_dias_no_da_turno(): void
    {
        $this->arm();
        $this->login();
        $again = $this->login();
        $this->assertArrayNotHasKey('grant', $again);
        $this->assertSame(0, $this->grants('reactivation'));
    }

    public function test_tras_la_inactividad_el_siguiente_ingreso_da_un_turno_una_sola_vez(): void
    {
        $cfg = $this->arm();
        $this->login();
        $this->assertTrue((new DemoGrants)->simulateInactivity($this->accountId(), 'tester', $cfg));

        $back = $this->login();
        $this->assertSame(['plan' => 'Bienvenida', 'kind' => 'reactivation'], $back['grant']);
        $this->assertCount(2, $this->codes($back));
        $this->assertArrayNotHasKey('grant', $this->login(), 'un solo turno por ventana, aunque ingrese varias veces');
        $this->assertSame(1, $this->grants('reactivation'));
    }

    public function test_una_compra_reinicia_la_espera_de_reactivacion(): void
    {
        $cfg = $this->arm();
        $s = $this->login();
        (new DemoGrants)->simulateInactivity($this->accountId(), 'tester', $cfg);
        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'b1000', 'quantity' => 1]]], ['Authorization' => 'Bearer '.$s['token'], 'Idempotency-Key' => 'clave-compra-1'])->assertOk();

        $this->assertArrayNotHasKey('grant', $this->login());
        $this->assertSame(0, $this->grants('reactivation'));
    }

    public function test_una_compra_rechazada_por_falta_de_codigos_no_reinicia_la_espera(): void
    {
        $cfg = $this->arm();
        $s = $this->login();
        DB::update("UPDATE demo_pool SET state = 'retired' WHERE plan = 'bronce'"); // Bronce agotado: la compra responde 409 y se revierte
        (new DemoGrants)->simulateInactivity($this->accountId(), 'tester', $cfg);
        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'b1000', 'quantity' => 1]]], ['Authorization' => 'Bearer '.$s['token'], 'Idempotency-Key' => 'clave-compra-2'])->assertStatus(409);

        $this->assertSame('reactivation', $this->login()['grant']['kind'], 'la compra fallida no cuenta como compra');
    }

    public function test_la_simulacion_solo_mueve_la_marca_hacia_atras(): void
    {
        $cfg = $this->arm();
        $this->login();
        $id = $this->accountId();
        DB::update("UPDATE demo_accounts SET activity_at = now() - interval '30 days' WHERE id = ?", [$id]);
        (new DemoGrants)->simulateInactivity($id, 'tester', $cfg);
        $days = (float) DB::selectOne('SELECT extract(epoch FROM now() - activity_at) / 86400 AS d FROM demo_accounts WHERE id = ?', [$id])->d;
        $this->assertEqualsWithDelta(30.0, $days, 0.01, 'una marca más vieja no se acerca al presente');

        DB::update('UPDATE demo_accounts SET activity_at = now() WHERE id = ?', [$id]);
        (new DemoGrants)->simulateInactivity($id, 'tester', $cfg);
        $days = (float) DB::selectOne('SELECT extract(epoch FROM now() - activity_at) / 86400 AS d FROM demo_accounts WHERE id = ?', [$id])->d;
        $this->assertEqualsWithDelta(7.0, $days, 0.01);
    }

    public function test_la_simulacion_queda_en_la_bitacora_y_en_el_modo_anterior_se_rechaza(): void
    {
        $cfg = $this->arm();
        $this->login();
        (new DemoGrants)->simulateInactivity($this->accountId(), 'tester', $cfg);
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM audit_log WHERE action = 'demo.inactivity_simulated'")->n);

        $this->expectException(AdminException::class);
        (new DemoGrants)->simulateInactivity($this->accountId(), 'tester', DemoConfig::validate(DemoConfig::defaults()));
    }

    public function test_simular_una_cuenta_inexistente_devuelve_falso_sin_bitacora(): void
    {
        $cfg = $this->arm();
        $this->assertFalse((new DemoGrants)->simulateInactivity('00000000-0000-4000-8000-000000000000', 'tester', $cfg));
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM audit_log WHERE action = 'demo.inactivity_simulated'")->n);
    }

    // --- Sin códigos de bienvenida (A7) ----------------------------------------------------------------------------------

    public function test_sin_codigos_de_bienvenida_el_ingreso_funciona_y_queda_anotado(): void
    {
        $this->arm();
        DB::update("UPDATE demo_pool SET state = 'retired' WHERE plan = 'welcome'");
        $s = $this->login();
        $this->assertTrue($s['isNew'], 'la cuenta se creó igual');
        $this->assertArrayNotHasKey('grant', $s);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_grants')->n, 'ningún turno a medias');
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM audit_log WHERE action = 'demo.grant_skipped'")->n);
    }

    public function test_sin_codigos_la_reactivacion_no_gasta_la_marca_y_se_reintenta_despues(): void
    {
        $cfg = $this->arm();
        $this->login();
        (new DemoGrants)->simulateInactivity($this->accountId(), 'tester', $cfg);
        DB::update("UPDATE demo_pool SET state = 'retired' WHERE plan = 'welcome' AND state = 'available'");
        $this->assertArrayNotHasKey('grant', $this->login());
        $this->assertSame(0, $this->grants('reactivation'));

        // El superadmin vuelve a sembrar: el siguiente ingreso SÍ otorga el turno (la marca no se gastó).
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $this->assertSame('reactivation', $this->login()['grant']['kind']);
    }

    // --- Garantías de la base ---------------------------------------------------------------------------------------------

    public function test_la_base_no_admite_dos_turnos_de_bienvenida_para_el_mismo_usuario(): void
    {
        DB::insert("INSERT INTO demo_grants (user_id, kind) VALUES ('standalone-dev:u1', 'welcome')");
        $this->assertDbRejects(fn () => DB::insert("INSERT INTO demo_grants (user_id, kind) VALUES ('standalone-dev:u1', 'welcome')"), 'demo_grants_one_welcome_idx');
        DB::insert("INSERT INTO demo_grants (user_id, kind) VALUES ('standalone-dev:u1', 'reactivation')");
        DB::insert("INSERT INTO demo_grants (user_id, kind) VALUES ('standalone-dev:u1', 'reactivation')"); // la reactivación puede repetirse en otra ventana
        $this->assertSame(2, $this->grants('reactivation'));
    }

    public function test_los_turnos_otorgados_son_de_solo_agregar(): void
    {
        DB::insert("INSERT INTO demo_grants (user_id, kind) VALUES ('standalone-dev:u1', 'welcome')");
        $this->assertDbRejects(fn () => DB::update("UPDATE demo_grants SET kind = 'reactivation'"), 'solo agregar');
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM demo_grants'), 'solo agregar');
    }

    public function test_un_codigo_se_entrega_por_una_compra_o_por_un_turno_no_por_ambos_ni_ninguno(): void
    {
        $this->arm();
        $this->expectException(\LogicException::class);
        DB::transaction(fn () => (new DemoPoolDraw)->draw('u', null, 1, 'welcome', null));
    }

    public function test_un_codigo_con_orden_y_turno_a_la_vez_lo_rechaza_la_base(): void
    {
        $this->arm();
        $gid = (int) DB::selectOne("INSERT INTO demo_grants (user_id, kind) VALUES ('standalone-dev:u1', 'welcome') RETURNING id")->id;
        $oid = (string) \Illuminate\Support\Str::uuid();
        DB::insert('INSERT INTO demo_orders (id, user_id, items, total, credited, codes_granted, idempotency_key, request_hash) VALUES (?, ?, ?::jsonb, 1, 0, 0, ?, ?)', [$oid, 'standalone-dev:u1', '[]', 'clave-uno-uno', str_repeat('a', 64)]);
        $this->assertDbRejects(
            fn () => DB::update("UPDATE demo_pool SET state = 'assigned', assigned_to = 'standalone-dev:u1', assigned_at = now(), order_id = ?, grant_id = ? WHERE id = (SELECT min(id) FROM demo_pool WHERE state = 'available')", [$oid, $gid]),
            'demo_pool_one_origin',
        );
    }

    public function test_un_codigo_asignado_no_cambia_de_turno(): void
    {
        $this->arm();
        $this->login();
        $gid = (int) DB::selectOne("INSERT INTO demo_grants (user_id, kind) VALUES ('standalone-dev:otro', 'reactivation') RETURNING id")->id;
        $this->assertDbRejects(fn () => DB::update("UPDATE demo_pool SET grant_id = ? WHERE state = 'assigned'", [$gid]), 'no se reasigna');
    }
}
