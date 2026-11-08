<?php

namespace Tests\Unit\Demo;

use App\Domain\Demo\DemoGameTemplates as T;
use PHPUnit\Framework\TestCase;

/** La traducción mezcla → campaña es pura: aquí se fija QUÉ premio queda con qué patrón/mano (el motor solo comprueba que sea válido). */
final class DemoGameTemplatesTest extends TestCase
{
    private function entry(array $amounts, int $none = 3): array
    {
        return ['prizes' => array_map(fn (int $a) => ['type' => 'money', 'amount' => $a, 'count' => 1], $amounts), 'noPrizeCount' => $none];
    }

    public function test_los_premios_se_numeran_de_menor_a_mayor_sin_importar_el_orden_del_panel(): void
    {
        $plan = T::plan('scratch', $this->entry([50000, 10000, 20000]));
        $byId = [];
        foreach ($plan['prizes'] as [$id, , $label]) {
            $byId[$id] = $label;
        }
        $this->assertSame(['snone' => 'Sin premio', 'sp1' => '$ 10.000', 'sp2' => '$ 20.000', 'sp3' => '$ 50.000'], $byId);
        $this->assertSame([['prize_id' => 'snone', 'quantity' => 3], ['prize_id' => 'sp1', 'quantity' => 1], ['prize_id' => 'sp2', 'quantity' => 1], ['prize_id' => 'sp3', 'quantity' => 1]], $plan['pool']);
    }

    public function test_bingo_da_el_carton_lleno_al_premio_mas_alto_y_la_linea_al_mas_bajo(): void
    {
        $three = T::plan('bingo', $this->entry([10000, 100000, 50000]))['config']['patterns'];
        $this->assertSame([['pattern' => 'full', 'prize' => 'gp3'], ['pattern' => 'corners', 'prize' => 'gp2'], ['pattern' => 'line', 'prize' => 'gp1']], $three);
        $two = T::plan('bingo', $this->entry([10000, 50000]))['config']['patterns'];
        $this->assertSame([['pattern' => 'corners', 'prize' => 'gp2'], ['pattern' => 'line', 'prize' => 'gp1']], $two);
        $one = T::plan('bingo', $this->entry([10000]))['config']['patterns'];
        $this->assertSame([['pattern' => 'line', 'prize' => 'gp1']], $one);
    }

    public function test_poker_paga_mas_cuanto_mejor_es_la_mano_sin_saltos(): void
    {
        $hands = T::plan('poker', $this->entry([10000, 50000, 100000]))['config']['hands'];
        $this->assertSame([
            'three_of_a_kind' => 'kp1', 'straight' => 'kp1', 'flush' => 'kp2', 'full_house' => 'kp2', 'four_of_a_kind' => 'kp3', 'straight_flush' => 'kp3',
        ], $hands);
        $one = T::plan('poker', $this->entry([10000]))['config']['hands'];
        $this->assertSame(['kp1'], array_values(array_unique($one)));
        $this->assertCount(6, $one);
        $six = T::plan('poker', $this->entry([1, 2, 3, 4, 5, 6]))['config']['hands'];
        $this->assertSame(['kp1', 'kp2', 'kp3', 'kp4', 'kp5', 'kp6'], array_values($six));
    }

    public function test_dados_reparte_los_dobles_a_los_premios_y_deja_el_resto_sin_premio(): void
    {
        $rolls = T::plan('dice', $this->entry([10000, 50000]))['config']['rolls'];
        $this->assertSame([[2, 2]], $rolls['dp1']);
        $this->assertSame([[3, 3]], $rolls['dp2']);
        $none = array_map(fn (array $c) => implode('-', $c), $rolls['dnone']);
        $this->assertCount(21 - 2, $none, 'las 21 combinaciones menos las 2 de premio');
        $this->assertContains('1-1', $none, 'un doble sin usar también es sin premio');
        $this->assertNotContains('2-2', $none);
        $all = array_merge(...array_values($rolls));
        $this->assertCount(21, array_unique(array_map(fn (array $c) => implode('-', $c), $all)), 'ninguna combinación en dos premios');
    }

    public function test_ruleta_tiene_entre_4_y_12_segmentos_con_todos_los_premios(): void
    {
        $few = T::plan('roulette', $this->entry([10000], 1))['config']['segments'];
        $this->assertCount(8, $few);
        $this->assertEqualsCanonicalizing(['rp1', 'rnone'], array_unique($few));
        $many = T::plan('roulette', $this->entry(range(1000, 11000, 1000), 2))['config']['segments'];
        $this->assertCount(12, $many);
        $this->assertCount(12, array_unique($many));
    }

    public function test_etiquetas_de_dinero_puntos_y_cupon(): void
    {
        $plan = T::plan('memory', ['prizes' => [['type' => 'money', 'amount' => 1250000, 'count' => 1], ['type' => 'points', 'amount' => 250, 'count' => 1], ['type' => 'coupon', 'amount' => 20, 'count' => 1], ['type' => 'coupon', 'amount' => 0, 'count' => 1]], 'noPrizeCount' => 0]);
        $labels = array_column($plan['prizes'], 2);
        $this->assertEqualsCanonicalizing(['$ 1.250.000', '250 puntos', 'Cupón 20%', 'Cupón'], $labels);
        $this->assertSame([], array_filter($plan['prizes'], fn (array $p) => $p[1] === 'none'), 'sin «sin premio» no se define ese premio');
    }
}
