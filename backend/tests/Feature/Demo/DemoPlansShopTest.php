<?php

namespace Tests\Feature\Demo;

use App\Domain\Admin\AdminException;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoPlans;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoSwitch;
use App\Mail\DemoLoginCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\DbTestCase;
use Tests\Support\HostTokens;
use Tests\Support\PlansFixture;

/** La muestra por planes de cara al usuario (SPEC-007 M11; CA-70, CA-71, CA-74, CA-76, CA-77, CA-79, T-D21, T-D22, T-D23). */
final class DemoPlansShopTest extends DbTestCase
{
    private const ISSUER = 'standalone-dev';

    private int $personas = 0;

    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure([self::ISSUER => ['alg' => 'HS256', 'key' => base64_encode(HostTokens::SECRET)]]);
        config(['gamification.demo.allowed' => true]);
        Mail::fake();
    }

    /** Mezclas chicas (la siembra espera lotes reales): Bienvenida 6, Bronce 2, Plata 6, Oro 6 códigos. */
    private function plansConfig(array $triggers = [], array $credit = []): array
    {
        return PlansFixture::config($triggers, $credit);
    }

    private function arm(?array $config = null): array
    {
        $cfg = $config ?? $this->plansConfig();
        $switch = new DemoSwitch;
        $switch->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $switch->enable('test');

        return $cfg;
    }

    /** @return array<string, mixed> */
    private function login(): array
    {
        $email = 'persona'.(++$this->personas).'@example.com';
        $this->postJson('/v1/demo/auth/request', ['email' => $email])->assertOk();
        $r = $this->postJson('/v1/demo/auth/verify', ['email' => $email, 'code' => Mail::sent(DemoLoginCodeMail::class)->last()->code]);
        $r->assertOk();

        return $r->json();
    }

    /** @param list<array<string, mixed>> $items */
    private function buy(array $session, array $items, string $key, int $expect = 200, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $r = $this->postJson('/v1/demo/purchase', ['items' => $items] + $extra, ['Authorization' => 'Bearer '.$session['token'], 'Idempotency-Key' => 'clave-'.$key]);
        $this->assertSame($expect, $r->status(), $r->getContent());

        return $r;
    }

    private function counts(): array
    {
        return [
            'orders' => (int) DB::selectOne('SELECT count(*) AS n FROM demo_orders')->n,
            'ledger' => (int) DB::selectOne('SELECT count(*) AS n FROM demo_wallet_ledger')->n,
            'assigned' => (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned'")->n,
        ];
    }

    private function available(string $plan): int
    {
        return (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available' AND plan = ?", [$plan])->n;
    }

    // --- Siembra por plan ---------------------------------------------------------------------------------------------

    public function test_la_siembra_crea_un_conjunto_de_codigos_por_plan_con_su_stock_exacto(): void
    {
        $this->arm();
        $rows = DB::select("SELECT plan, count(*) AS n FROM demo_pool WHERE state = 'available' GROUP BY plan ORDER BY plan");
        $this->assertSame(['bronce' => 2, 'oro' => 6, 'plata' => 6, 'welcome' => 6], collect($rows)->pluck('n', 'plan')->map(fn ($n) => (int) $n)->all());
    }

    public function test_la_probabilidad_calculada_coincide_con_el_pool_realmente_sembrado(): void
    {
        $cfg = $this->arm();
        foreach ($cfg['plans'] as $plan) {
            $total = (int) DB::selectOne('SELECT count(*) AS n FROM demo_pool WHERE plan = ?', [$plan['id']])->n;
            $sinPremio = (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE plan = ? AND description LIKE '%Sin premio'", [$plan['id']])->n;
            $this->assertEqualsWithDelta(DemoPlans::probability($plan), ($total - $sinPremio) / $total, 1e-12, $plan['id']);
        }
    }

    public function test_el_modo_anterior_siembra_sin_plan(): void
    {
        $cfg = DemoConfig::validate(DemoConfig::defaults());
        (new DemoSwitch)->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_pool WHERE plan IS NOT NULL')->n);
        $this->assertSame(10, (int) DB::selectOne('SELECT count(*) AS n FROM demo_pool WHERE plan IS NULL')->n);
    }

    public function test_el_plan_de_un_codigo_no_se_puede_cambiar_ni_siquiera_en_la_base(): void
    {
        $this->arm();
        $this->assertDbRejects(fn () => DB::update("UPDATE demo_pool SET plan = 'oro' WHERE plan = 'bronce'"), 'el plan de un código');
    }

    public function test_encender_exige_al_menos_un_codigo_disponible_en_cada_plan(): void
    {
        $cfg = $this->plansConfig();
        $switch = new DemoSwitch;
        $switch->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        DB::update("UPDATE demo_pool SET state = 'retired' WHERE plan = 'oro'");
        try {
            $switch->enable('test');
            $this->fail('No debía dejar encender con el plan Oro vacío.');
        } catch (AdminException $e) {
            $this->assertStringContainsString('Oro', $e->getMessage());
        }
        $this->assertFalse($switch->isActive());
    }

    // --- Elección del plan en la compra --------------------------------------------------------------------------------

    public function test_cada_frontera_de_monto_entrega_el_plan_que_corresponde(): void
    {
        $this->arm();
        $s = $this->login();

        $this->assertSame([], $this->buy($s, [['productId' => 'a999', 'quantity' => 1]], 'k-999')->json('codes'), '$999: ningún turno');
        $this->assertSame('Bronce', $this->buy($s, [['productId' => 'b1000', 'quantity' => 1]], 'k-1000')->json('codes.0.plan'));
        $this->assertSame('Bronce', $this->buy($s, [['productId' => 'b1000', 'quantity' => 10]], 'k-10000')->json('codes.0.plan'), '$10.000 exactos: Bronce');
        $this->assertSame('Plata', $this->buy($s, [['productId' => 'c10001', 'quantity' => 1]], 'k-10001')->json('codes.0.plan'), '$10.001: Plata');
        $this->assertSame('Oro', $this->buy($s, [['productId' => 'd50001', 'quantity' => 1]], 'k-50001')->json('codes.0.plan'), '$50.001: Oro');
    }

    public function test_el_monto_de_plata_llega_hasta_50000_y_oro_empieza_en_50001(): void
    {
        $this->arm();
        $s = $this->login();
        // 4 × 10.001 = 40.004 (Plata); 5 × 10.001 = 50.005 (Oro): las fronteras exactas $50.000/$50.001 están en la prueba unitaria.
        $this->assertSame('Plata', $this->buy($s, [['productId' => 'c10001', 'quantity' => 4]], 'k-40004')->json('codes.0.plan'));
        $this->assertSame('Oro', $this->buy($s, [['productId' => 'c10001', 'quantity' => 5]], 'k-50005')->json('codes.0.plan'));
    }

    public function test_un_producto_que_no_participa_no_da_turno_pero_la_compra_se_procesa_y_acredita(): void
    {
        $this->arm();
        $s = $this->login();
        $before = $this->counts();
        $r = $this->buy($s, [['productId' => 'pasa', 'quantity' => 3]], 'k-pasa');
        $this->assertSame([], $r->json('codes'));
        $this->assertSame(60_000, $r->json('total'));
        $this->assertSame(6_000, $r->json('credited'), 'D31: el saldo simulado se mantiene sobre toda la compra');
        $this->assertSame($before['orders'] + 1, $this->counts()['orders']);
        $this->assertSame($before['assigned'], $this->counts()['assigned']);
    }

    public function test_en_una_compra_mixta_el_plan_sale_solo_de_lo_que_participa(): void
    {
        $this->arm();
        $s = $this->login();
        // $20.000 de Pasa Plata + $1.000 que participa = $21.000 en total, pero el plan es el de $1.000 (Bronce), no Plata.
        $r = $this->buy($s, [['productId' => 'pasa', 'quantity' => 1], ['productId' => 'b1000', 'quantity' => 1]], 'k-mixta');
        $this->assertSame(21_000, $r->json('total'));
        $this->assertSame('Bronce', $r->json('codes.0.plan'));
    }

    public function test_el_cliente_no_puede_elegir_su_plan(): void
    {
        $this->arm();
        $s = $this->login();
        // Un plan o un monto en el cuerpo se ignoran: manda el catálogo.
        $r = $this->buy($s, [['productId' => 'a999', 'quantity' => 1]], 'k-extra', extra: ['plan' => 'oro', 'amount' => 99_999_999, 'eligible' => 99_999_999]);
        $this->assertSame([], $r->json('codes'));
        // Un plan dentro de una línea se rechaza.
        $this->buy($s, [['productId' => 'b1000', 'quantity' => 1, 'plan' => 'oro']], 'k-linea', 422);
        $this->buy($s, [['productId' => 'b1000', 'quantity' => 1, 'participates' => true]], 'k-linea2', 422);
        $this->assertSame(1, $this->counts()['orders'], 'solo la compra válida quedó registrada');
    }

    public function test_dos_compras_hasta_el_tope_por_sesion_y_el_tope_aplica_a_los_turnos_por_compra(): void
    {
        $this->arm($this->plansConfig(['turnsPerPurchase' => 3], ['maxCodesPerSession' => 2]));
        $s = $this->login();
        $r = $this->buy($s, [['productId' => 'd50001', 'quantity' => 1]], 'k-oro');
        $this->assertCount(2, $r->json('codes'), '3 turnos pedidos, 2 de tope por sesión');
        $this->assertTrue($r->json('capReached'));
        $this->assertCount(0, $this->buy($s, [['productId' => 'd50001', 'quantity' => 1]], 'k-oro2')->json('codes'));
    }

    // --- Agotamiento, idempotencia y estado ---------------------------------------------------------------------------

    public function test_un_plan_agotado_responde_409_sin_dejar_estado_a_medias_y_sin_tocar_los_demas(): void
    {
        $this->arm();
        $s = $this->login();
        $this->buy($s, [['productId' => 'b1000', 'quantity' => 1]], 'k-1');
        $this->buy($s, [['productId' => 'b1000', 'quantity' => 1]], 'k-2');
        $this->assertSame(0, $this->available('bronce'));

        $before = $this->counts();
        $others = [$this->available('plata'), $this->available('oro'), $this->available('welcome')];
        $r = $this->buy($s, [['productId' => 'b1000', 'quantity' => 1]], 'k-3', 409);
        $r->assertJsonPath('error.code', 'demoPoolExhausted');
        $this->assertSame($before, $this->counts(), 'ni orden, ni saldo, ni códigos asignados');
        $this->assertSame($others, [$this->available('plata'), $this->available('oro'), $this->available('welcome')]);

        $this->buy($s, [['productId' => 'd50001', 'quantity' => 1]], 'k-oro'); // Oro sigue funcionando
    }

    public function test_repetir_la_misma_clave_devuelve_el_mismo_resultado_con_el_nombre_del_plan(): void
    {
        $this->arm();
        $s = $this->login();
        $first = $this->buy($s, [['productId' => 'c10001', 'quantity' => 1]], 'k-rep');
        $second = $this->buy($s, [['productId' => 'c10001', 'quantity' => 1]], 'k-rep');
        $this->assertSame($first->json('codes'), $second->json('codes'));
        $this->assertSame('Plata', $second->json('codes.0.plan'));
        $this->assertTrue($second->json('replayed'));
        $this->assertSame(1, $this->counts()['orders']);
    }

    public function test_la_cuenta_muestra_el_plan_de_cada_codigo_y_la_app_no_recibe_ids_ni_umbrales(): void
    {
        $this->arm();
        $s = $this->login();
        $this->buy($s, [['productId' => 'd50001', 'quantity' => 1]], 'k-oro');
        $r = $this->getJson('/v1/demo/account', ['Authorization' => 'Bearer '.$s['token']])->assertOk();
        $this->assertEqualsCanonicalizing(['Bienvenida', 'Oro'], array_column($r->json('codes'), 'plan'), 'el turno de bienvenida y el de la compra');
        $this->assertStringNotContainsString('maxMoneyPrize', $r->getContent());
        $this->assertStringNotContainsString('oro"', $r->getContent(), 'el id interno del plan no sale');
        $this->assertStringNotContainsString('minAmount', json_encode($s));
        $this->assertStringNotContainsString('probab', strtolower(json_encode($s)));
    }

    public function test_la_sesion_muestra_como_compra_minima_la_del_primer_plan_por_monto(): void
    {
        $this->arm();
        $this->assertSame(1000, $this->login()['rules']['minPurchase']);
    }

    // --- Compatibilidad con el modo anterior (CA-79) ------------------------------------------------------------------

    public function test_el_modo_anterior_no_agrega_el_campo_plan_a_los_codigos(): void
    {
        $cfg = DemoConfig::validate(DemoConfig::defaults());
        (new DemoSwitch)->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        (new DemoSwitch)->enable('test');
        $s = $this->login();
        $this->buy($s, [['productId' => 'recarga-20', 'quantity' => 1]], 'k-ok');
        $r = $this->getJson('/v1/demo/account', ['Authorization' => 'Bearer '.$s['token']])->assertOk();
        $this->assertArrayNotHasKey('plan', $r->json('codes.0'));
    }
}
