<?php

namespace Tests\Feature\Admin;

use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoShop;
use App\Domain\Demo\DemoSwitch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\HostTokens;
use Tests\Support\PlansFixture;

/** Planes de la muestra desde el panel (SPEC-007 M12; T-D27, T-D28): existencias por plan, configuración y la simulación de inactividad. */
final class DemoPlansAdminTest extends AdminTestCase
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

    private function arm(?array $config = null): void
    {
        $cfg = $config ?? PlansFixture::config();
        $switch = new DemoSwitch;
        $switch->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $switch->enable('test');
    }

    private function superadmin(): void
    {
        $this->login($this->makeAdmin('superadmin'));
    }

    /** Crea una cuenta (con su turno de bienvenida) sin pasar por el correo. */
    private function account(): string
    {
        (new DemoShop)->login('ana@example.com', '203.0.113.9');

        return DB::selectOne('SELECT id FROM demo_accounts LIMIT 1')->id;
    }

    public function test_solo_el_superadmin_puede_simular_la_inactividad(): void
    {
        $this->arm();
        $id = $this->account();
        $this->assertSame(401, $this->api('POST', "/demo/accounts/$id/simulate-inactivity")->status());
        foreach (['operator', 'auditor'] as $role) {
            $this->login($this->makeAdmin($role));
            $this->assertSame(403, $this->api('POST', "/demo/accounts/$id/simulate-inactivity")->status(), $role);
            $this->api('POST', '/auth/logout');
        }
        $this->superadmin();
        $this->assertSame(204, $this->api('POST', "/demo/accounts/$id/simulate-inactivity")->status());
    }

    public function test_la_simulacion_deja_la_cuenta_lista_para_la_reactivacion_y_queda_en_la_bitacora(): void
    {
        $this->arm();
        $id = $this->account();
        $this->superadmin();
        $this->assertSame(204, $this->api('POST', "/demo/accounts/$id/simulate-inactivity")->status());

        $again = (new DemoShop)->login('ana@example.com', '203.0.113.9');
        $this->assertSame('reactivation', $again['grant']['kind']);
        $entry = DB::selectOne("SELECT actor, data FROM audit_log WHERE action = 'demo.inactivity_simulated'");
        $this->assertNotNull($entry);
        $this->assertSame(['days' => 7], json_decode($entry->data, true));
        $this->assertStringNotContainsString('ana', $entry->data);
    }

    public function test_ids_malos_o_desconocidos_son_404_y_en_el_modo_anterior_es_409(): void
    {
        $this->arm();
        $this->superadmin();
        $this->assertSame(404, $this->api('POST', '/demo/accounts/no-es-un-id/simulate-inactivity')->status());
        $this->assertSame(404, $this->api('POST', '/demo/accounts/'.Str::uuid().'/simulate-inactivity')->status());
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM audit_log WHERE action = 'demo.inactivity_simulated'")->n);

        (new DemoSwitch)->saveConfig(DemoConfig::validate(DemoConfig::defaults()), 'test');
        $r = $this->api('POST', '/demo/accounts/'.Str::uuid().'/simulate-inactivity');
        $this->assertSame(409, $r->status());
        $this->assertSame('wrongState', $r->json('error.code'));
    }

    public function test_el_estado_trae_las_existencias_y_la_probabilidad_por_plan(): void
    {
        $this->superadmin();
        $this->arm();
        $this->account();
        $r = $this->api('GET', '/demo');
        $this->assertSame(200, $r->status());
        $by = collect($r->json('poolByPlan'))->keyBy('id');
        $this->assertSame(['welcome', 'bronce', 'plata', 'oro'], $by->keys()->all());
        $this->assertSame(['name' => 'Bienvenida', 'available' => 5, 'assigned' => 1], collect($by['welcome'])->only(['name', 'available', 'assigned'])->all());
        $this->assertSame(2, $by['bronce']['available']);
        $this->assertEqualsWithDelta(1.0, $by['welcome']['probability'], 1e-12);
        $this->assertEqualsWithDelta(0.5, $by['bronce']['probability'], 1e-12);
        $this->assertEqualsWithDelta(0.5, $by['oro']['probability'], 1e-12);
        $this->assertSame(['steps', 'plans'], $r->json('limits.modes'));
        $this->assertSame('welcome', $r->json('limits.welcomePlan'));
        $this->assertSame(DemoConfig::MAX_AMOUNT_PLANS, $r->json('limits.maxAmountPlans'));
    }

    public function test_en_el_modo_anterior_no_hay_existencias_por_plan(): void
    {
        $this->superadmin();
        $this->assertSame([], $this->api('GET', '/demo')->json('poolByPlan'));
        (new DemoSwitch)->saveConfig(DemoConfig::validate(DemoConfig::defaults()), 'test');
        $this->assertSame([], $this->api('GET', '/demo')->json('poolByPlan'));
    }

    public function test_el_panel_guarda_la_configuracion_por_planes_y_rechaza_rangos_rotos(): void
    {
        $this->superadmin();
        $cfg = PlansFixture::config();
        $this->assertSame(200, $this->api('PUT', '/demo/config', $cfg)->status());
        $this->assertSame('plans', $this->api('GET', '/demo')->json('config.mode'));

        $bad = $cfg;
        $bad['plans'][2]['minAmount'] = 10_005;
        $r = $this->api('PUT', '/demo/config', $bad);
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('sin huecos ni solapes', $r->json('error.message'));
    }

    public function test_el_panel_no_filtra_la_mezcla_de_los_planes_a_la_app(): void
    {
        $this->arm();
        $s = (new DemoShop)->login('ana@example.com', '203.0.113.9');
        $json = json_encode($s);
        foreach (['mix', 'maxMoneyPrize', 'minAmount', 'probability', 'triggers', 'poolByPlan'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
    }
}
