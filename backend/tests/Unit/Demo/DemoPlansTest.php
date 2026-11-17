<?php

namespace Tests\Unit\Demo;

use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoPlans;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Planes de la muestra (SPEC-007 M11): validación de la configuración, elección del plan por monto y probabilidad exacta. Todo puro. */
final class DemoPlansTest extends TestCase
{
    /** Los valores PROVISIONALES de la spec §3.1. */
    private function plansConfig(): array
    {
        $c = DemoConfig::defaults();
        $c['mode'] = 'plans';
        $c['products'] = [
            ['id' => 'recarga-20', 'name' => 'Recarga $20.000', 'price' => 20_000, 'participates' => true],
            ['id' => 'pasa-plata', 'name' => 'Pasa Plata', 'price' => 20_000, 'participates' => false],
        ];
        $roulette = static fn (array $prizes, int $none): array => ['roulette' => ['prizes' => $prizes, 'noPrizeCount' => $none]];
        $money = static fn (int $amount, int $count): array => ['type' => 'money', 'amount' => $amount, 'count' => $count];
        $coupon = static fn (int $count): array => ['type' => 'coupon', 'amount' => 0, 'count' => $count];
        $c['plans'] = [
            ['id' => 'welcome', 'kind' => 'event', 'name' => 'Bienvenida', 'maxMoneyPrize' => 5_000, 'mix' => $roulette([$money(2_000, 45), $money(3_000, 45), $money(5_000, 10)], 0)],
            ['id' => 'bronce', 'kind' => 'amount', 'name' => 'Bronce', 'minAmount' => 1_000, 'maxAmount' => 10_000, 'maxMoneyPrize' => 10_000, 'mix' => $roulette([$money(10_000, 1), $coupon(4)], 95)],
            ['id' => 'plata', 'kind' => 'amount', 'name' => 'Plata', 'minAmount' => 10_001, 'maxAmount' => 50_000, 'maxMoneyPrize' => 20_000, 'mix' => $roulette([$money(20_000, 1), $money(10_000, 2), $coupon(12)], 85)],
            ['id' => 'oro', 'kind' => 'amount', 'name' => 'Oro', 'minAmount' => 50_001, 'maxAmount' => null, 'maxMoneyPrize' => 30_000, 'mix' => $roulette([$money(30_000, 2), $money(20_000, 5), $money(10_000, 13), $coupon(20)], 60)],
        ];
        $c['triggers'] = ['reactivationDays' => 7, 'turnsPerPurchase' => 1, 'welcomeOnFirstLogin' => true];
        unset($c['mix']);

        return $c;
    }

    private function assertRejects(array $config, string $fragment): void
    {
        try {
            DemoConfig::validate($config);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());

            return;
        }
        $this->fail("Debía rechazarse («{$fragment}»).");
    }

    public function test_los_valores_provisionales_de_la_spec_son_validos_y_suman_400_codigos(): void
    {
        $v = DemoConfig::validate($this->plansConfig());
        $this->assertSame('plans', $v['mode']);
        $this->assertSame(400, DemoConfig::poolSize($v));
        $this->assertSame([], $v['mix']);
        $this->assertSame(['reactivationDays' => 7, 'turnsPerPurchase' => 1, 'welcomeOnFirstLogin' => true], $v['triggers']);
        $this->assertTrue($v['products'][0]['participates']);
        $this->assertFalse($v['products'][1]['participates']);
    }

    public function test_la_probabilidad_sale_de_la_mezcla_y_es_exacta(): void
    {
        $v = DemoConfig::validate($this->plansConfig());
        $p = [];
        foreach ($v['plans'] as $plan) {
            $p[$plan['id']] = DemoPlans::probability($plan);
        }
        $this->assertSame(1.0, $p['welcome'], 'Bienvenida: 100 %');
        $this->assertEqualsWithDelta(0.05, $p['bronce'], 1e-12);
        $this->assertEqualsWithDelta(0.15, $p['plata'], 1e-12);
        $this->assertEqualsWithDelta(0.40, $p['oro'], 1e-12);
    }

    /** @return iterable<string, array{int, string|null}> */
    public static function fronteras(): iterable
    {
        yield '0' => [0, null];
        yield '999' => [999, null];
        yield '1.000' => [1_000, 'bronce'];
        yield '10.000' => [10_000, 'bronce'];
        yield '10.001' => [10_001, 'plata'];
        yield '50.000' => [50_000, 'plata'];
        yield '50.001' => [50_001, 'oro'];
        yield '10 millones' => [10_000_000, 'oro'];
    }

    #[DataProvider('fronteras')]
    public function test_el_plan_por_monto_respeta_cada_frontera(int $amount, ?string $expected): void
    {
        $v = DemoConfig::validate($this->plansConfig());
        $this->assertSame($expected, DemoPlans::forAmount($v, $amount)['id'] ?? null);
    }

    public function test_el_monto_negativo_no_tiene_plan(): void
    {
        $this->assertNull(DemoPlans::forAmount(DemoConfig::validate($this->plansConfig()), -5));
    }

    public function test_solo_suma_para_el_plan_lo_que_participa(): void
    {
        $v = DemoConfig::validate($this->plansConfig());
        $lines = [['productId' => 'recarga-20', 'quantity' => 1], ['productId' => 'pasa-plata', 'quantity' => 3]];
        $this->assertSame(20_000, DemoPlans::eligibleTotal($lines, $v), 'los $60.000 de Pasa Plata no cuentan');
        $this->assertSame(0, DemoPlans::eligibleTotal([['productId' => 'pasa-plata', 'quantity' => 2]], $v));
        $this->assertSame(0, DemoPlans::eligibleTotal([['productId' => 'no-existe', 'quantity' => 1]], $v));
    }

    // --- Validación ----------------------------------------------------------------------------------------------------

    public function test_sin_modo_es_el_modo_anterior_y_la_salida_no_cambia(): void
    {
        $v = DemoConfig::validate(DemoConfig::defaults());
        $this->assertArrayNotHasKey('mode', $v);
        $this->assertArrayNotHasKey('plans', $v);
        $this->assertArrayNotHasKey('triggers', $v);
        $this->assertArrayNotHasKey('participates', $v['products'][0]);
        $this->assertSame('steps', DemoConfig::mode($v));
        $this->assertSame(10, DemoConfig::poolSize($v));
    }

    public function test_el_modo_anterior_no_admite_planes_ni_disparadores_ni_participa(): void
    {
        $c = DemoConfig::defaults();
        $this->assertRejects($c + ['plans' => []], 'plans: solo existe en el modo');
        $this->assertRejects($c + ['triggers' => []], 'triggers: solo existe en el modo');
        $c['products'][0]['participates'] = false;
        $this->assertRejects($c, 'participates: solo existe en el modo');
    }

    public function test_el_modo_debe_ser_steps_o_plans(): void
    {
        $c = $this->plansConfig();
        $c['mode'] = 'otro';
        $this->assertRejects($c, 'mode:');
    }

    public function test_rangos_con_hueco_o_solape_se_rechazan(): void
    {
        $c = $this->plansConfig();
        $c['plans'][2]['minAmount'] = 10_002;
        $this->assertRejects($c, 'debe ser 10001');
        $c = $this->plansConfig();
        $c['plans'][2]['minAmount'] = 10_000;
        $this->assertRejects($c, 'debe ser 10001');
    }

    public function test_el_ultimo_plan_por_monto_no_puede_tener_tope_y_los_demas_si(): void
    {
        $c = $this->plansConfig();
        $c['plans'][3]['maxAmount'] = 99_999;
        $this->assertRejects($c, 'no debe tener tope');
        $c = $this->plansConfig();
        $c['plans'][1]['maxAmount'] = null;
        $this->assertRejects($c, 'sin tope debe ser el último');
    }

    public function test_un_tope_menor_que_el_minimo_se_rechaza(): void
    {
        $c = $this->plansConfig();
        $c['plans'][1]['maxAmount'] = 999;
        $this->assertRejects($c, 'maxAmount');
    }

    public function test_exige_un_solo_plan_de_bienvenida_con_su_id(): void
    {
        $c = $this->plansConfig();
        array_shift($c['plans']);
        $this->assertRejects($c, 'plan de bienvenida');

        $c = $this->plansConfig();
        $c['plans'][0]['id'] = 'otro';
        $this->assertRejects($c, 'el plan de evento se llama');

        $c = $this->plansConfig();
        $c['plans'][1]['id'] = 'welcome';
        $this->assertRejects($c, 'repetido');
    }

    public function test_ids_repetidos_o_invalidos_y_nombres_vacios(): void
    {
        $c = $this->plansConfig();
        $c['plans'][2]['id'] = 'bronce';
        $this->assertRejects($c, 'repetido');
        $c = $this->plansConfig();
        $c['plans'][2]['id'] = "plata\n";
        $this->assertRejects($c, 'plans[2].id');
        $c = $this->plansConfig();
        $c['plans'][2]['name'] = '  ';
        $this->assertRejects($c, 'plans[2].name');
        $c = $this->plansConfig();
        $c['plans'][2]['name'] = str_repeat('x', DemoConfig::MAX_PLAN_NAME + 1);
        $this->assertRejects($c, 'plans[2].name');
    }

    public function test_un_premio_en_dinero_sobre_el_tope_del_plan_se_rechaza_pero_un_cupon_o_puntos_no(): void
    {
        $c = $this->plansConfig();
        $c['plans'][1]['mix']['roulette']['prizes'][0]['amount'] = 10_001;
        $this->assertRejects($c, 'supera el premio máximo en dinero del plan');

        $c = $this->plansConfig();
        $c['plans'][1]['mix']['roulette']['prizes'][] = ['type' => 'points', 'amount' => 50_000, 'count' => 1];
        $this->assertSame('points', DemoConfig::validate($c)['plans'][1]['mix']['roulette']['prizes'][2]['type'], 'los puntos no son dinero');
    }

    public function test_el_pool_total_de_todos_los_planes_no_puede_superar_el_techo(): void
    {
        $c = $this->plansConfig();
        $c['plans'][1]['mix']['roulette']['noPrizeCount'] = 195; // 400 − 95 + 195 = 500: justo en el techo
        $this->assertSame(500, DemoConfig::poolSize(DemoConfig::validate($c)));
        $c['plans'][1]['mix']['roulette']['noPrizeCount'] = 196;
        $this->assertRejects($c, 'el pool total no puede superar 500');
    }

    public function test_planes_por_monto_de_mas_se_rechazan(): void
    {
        $c = $this->plansConfig();
        for ($i = 0; $i < DemoConfig::MAX_AMOUNT_PLANS; $i++) {
            $c['plans'][] = $c['plans'][1];
        }
        $this->assertRejects($c, 'plans:');
    }

    public function test_los_disparadores_tienen_techos_duros(): void
    {
        $c = $this->plansConfig();
        $c['triggers']['reactivationDays'] = 0;
        $this->assertRejects($c, 'triggers.reactivationDays');
        $c['triggers']['reactivationDays'] = DemoConfig::MAX_REACTIVATION_DAYS + 1;
        $this->assertRejects($c, 'triggers.reactivationDays');
        $c = $this->plansConfig();
        $c['triggers']['turnsPerPurchase'] = DemoConfig::MAX_CODES_PER_PURCHASE + 1;
        $this->assertRejects($c, 'triggers.turnsPerPurchase');
        $c = $this->plansConfig();
        $c['triggers']['welcomeOnFirstLogin'] = 1;
        $this->assertRejects($c, 'welcomeOnFirstLogin');
        $c = $this->plansConfig();
        unset($c['triggers']);
        $this->assertRejects($c, 'triggers');
    }

    public function test_participa_debe_ser_booleano_estricto(): void
    {
        $c = $this->plansConfig();
        $c['products'][1]['participates'] = 'false';
        $this->assertRejects($c, 'participates: verdadero o falso');
        $c['products'][1]['participates'] = 0;
        $this->assertRejects($c, 'participates: verdadero o falso');
    }

    public function test_participa_omitido_vale_si_en_el_modo_planes(): void
    {
        $c = $this->plansConfig();
        unset($c['products'][1]['participates']);
        $this->assertTrue(DemoConfig::validate($c)['products'][1]['participates']);
    }

    public function test_en_el_modo_planes_la_mezcla_antigua_se_descarta(): void
    {
        $c = $this->plansConfig();
        $c['mix'] = ['roulette' => ['prizes' => [['type' => 'money', 'amount' => 1, 'count' => 1]], 'noPrizeCount' => 1]];
        $this->assertSame([], DemoConfig::validate($c)['mix']);
    }

    public function test_la_validacion_es_idempotente(): void
    {
        $v = DemoConfig::validate($this->plansConfig());
        $this->assertSame($v, DemoConfig::validate($v));
    }
}
