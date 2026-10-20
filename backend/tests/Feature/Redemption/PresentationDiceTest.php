<?php

namespace Tests\Feature\Redemption;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Redemption\Presentation;
use App\Engine\Pool;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/** Dados (SPEC-004): la combinación mostrada SIEMPRE pertenece a las del premio ya decidido. */
final class PresentationDiceTest extends DbTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
    }

    private function pool(array $map = ['none' => 10, 'p10k' => 4, 'p50k' => 1, 'p100k' => 1]): Pool
    {
        $e = [];
        foreach ($map as $id => $q) {
            $e[] = ['prize_id' => $id, 'quantity' => $q];
        }

        return Pool::fromEntries($e);
    }

    private function defs(): array
    {
        return [
            'none' => ['id' => 'none', 'type' => 'none', 'label' => 'Sin premio'],
            'p10k' => ['id' => 'p10k', 'type' => 'money', 'label' => '$ 10.000', 'amount' => 10000],
            'p50k' => ['id' => 'p50k', 'type' => 'money', 'label' => '$ 50.000', 'amount' => 50000],
            'p100k' => ['id' => 'p100k', 'type' => 'money', 'label' => '$ 100.000', 'amount' => 100000],
        ];
    }

    private function rejects(array $config, string $fragment, ?Pool $pool = null): void
    {
        try {
            Presentation::assertRepresentable($this->f->campaign('dice'), 'dice', $config, $pool ?? $this->pool());
            $this->fail("debía rechazar (esperaba «$fragment»)");
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    private function cfg(array $override = []): array
    {
        return $override + Fixtures::gameConfig('dice');
    }

    // --- Validación -------------------------------------------------------------------------------------------------

    public function test_una_configuracion_valida_se_acepta(): void
    {
        Presentation::assertRepresentable($this->f->campaign('dice'), 'dice', $this->cfg(), $this->pool());
        $this->addToAssertionCount(1);
    }

    public function test_exige_el_mapa_de_combinaciones(): void
    {
        $this->rejects([], 'necesita game_config.rolls');
        $this->rejects(['rolls' => []], 'necesita game_config.rolls');
        $this->rejects(['rolls' => [['a']]], 'necesita game_config.rolls');
    }

    public function test_un_premio_del_pool_sin_combinaciones_se_rechaza(): void
    {
        $rolls = Fixtures::diceRolls();
        unset($rolls['p50k']);
        $this->rejects($this->cfg(['rolls' => $rolls]), 'ninguna combinación para: p50k');
    }

    public function test_un_premio_con_cantidad_cero_no_exige_combinaciones(): void
    {
        $rolls = Fixtures::diceRolls();
        unset($rolls['p50k']);
        Presentation::assertRepresentable($this->f->campaign('dice'), 'dice', $this->cfg(['rolls' => $rolls]), $this->pool(['none' => 10, 'p10k' => 4, 'p50k' => 0, 'p100k' => 1]));
        $this->addToAssertionCount(1);
    }

    public function test_una_combinacion_en_dos_premios_es_ambigua_aunque_este_en_otro_orden(): void
    {
        $rolls = Fixtures::diceRolls();
        $rolls['p50k'] = [[1, 1], [6, 5]]; // [5,6] ya es de "sin premio"
        $this->rejects($this->cfg(['rolls' => $rolls]), 'asignada a dos premios');
    }

    public function test_rechaza_caras_y_formas_invalidas(): void
    {
        foreach ([[[0, 1]], [[1, 7]], [[1, 2, 3]], [[1]], [['1', 2]], [[1.5, 2]], 'x'] as $bad) {
            $rolls = Fixtures::diceRolls();
            $rolls['p50k'] = $bad;
            $this->rejects($this->cfg(['rolls' => $rolls]), 'Dados');
        }
    }

    public function test_rechaza_un_premio_que_la_campania_no_define_y_parametros_invalidos(): void
    {
        $this->rejects($this->cfg(['rolls' => Fixtures::diceRolls() + ['fantasma' => [[6, 6]]]]), 'no define: fantasma');
        $this->rejects($this->cfg(['diceCount' => 3]), '2 dados');
        $this->rejects($this->cfg(['rollDurationMs' => 100]), 'rollDurationMs');
        $this->rejects($this->cfg(['rollDurationMs' => 9000]), 'rollDurationMs');
    }

    // --- Construcción (propiedades) ---------------------------------------------------------------------------------

    public function test_la_combinacion_siempre_pertenece_al_premio_decidido(): void
    {
        $rolls = Fixtures::diceRolls();
        $presentation = new Presentation;
        foreach (array_keys($this->defs()) as $prize) {
            $seen = [];
            for ($i = 0; $i < 400; $i++) {
                $r = $presentation->build('dice', $this->cfg(), $this->defs(), $prize);
                $dice = $r['gameResult']['dice'];
                $this->assertCount(2, $dice);
                $sorted = $dice;
                sort($sorted);
                $this->assertContains($sorted, $rolls[$prize], "los dados {$dice[0]}-{$dice[1]} no son de $prize");
                $seen[implode('-', $sorted)] = true;
            }
            $this->assertCount(count($rolls[$prize]), $seen, "con 400 tiradas salen todas las combinaciones de $prize");
        }
    }

    public function test_el_orden_de_los_dados_tambien_varia(): void
    {
        $orders = [];
        for ($i = 0; $i < 200; $i++) {
            $d = (new Presentation)->build('dice', $this->cfg(), $this->defs(), 'p100k')['gameResult']['dice'];
            $orders[implode('-', $d)] = true;
        }
        $this->assertSame(['6-6' => true], $orders, 'los dobles solo tienen un orden');

        $orders = [];
        for ($i = 0; $i < 200; $i++) {
            $d = (new Presentation)->build('dice', $this->cfg(['rolls' => ['none' => [[1, 2]]] + Fixtures::diceRolls()]), $this->defs(), 'none')['gameResult']['dice'];
            $orders[implode('-', $d)] = true;
        }
        $keys = array_keys($orders);
        sort($keys);
        $this->assertSame(['1-2', '2-1'], $keys, 'una combinación no doble sale en los dos órdenes');
    }

    public function test_entrega_la_leyenda_con_premios_completos_y_combinaciones_ordenadas(): void
    {
        $r = (new Presentation)->build('dice', $this->cfg(['rollDurationMs' => 2500]), $this->defs(), 'p10k');
        $this->assertSame(['diceCount' => 2, 'rollDurationMs' => 2500], array_diff_key($r['gameConfig'], ['legend' => 1]));
        $legend = $r['gameConfig']['legend'];
        $this->assertCount(4, $legend);
        $p100k = array_values(array_filter($legend, fn ($l) => $l['prize']['id'] === 'p100k'))[0];
        $this->assertSame([[6, 6]], $p100k['rolls']);
        $this->assertSame('$ 100.000', $p100k['prize']['label']);
        foreach ($legend as $l) {
            foreach ($l['rolls'] as [$a, $b]) {
                $this->assertLessThanOrEqual($b, $a, 'ordenadas a ≤ b');
            }
        }
    }

    public function test_un_premio_sin_combinaciones_falla_con_claridad_al_construir(): void
    {
        $rolls = Fixtures::diceRolls();
        unset($rolls['p50k']);
        $this->expectException(\LogicException::class);
        (new Presentation)->build('dice', $this->cfg(['rolls' => $rolls]), $this->defs(), 'p50k');
    }
}
