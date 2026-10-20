<?php

namespace Tests\Feature\Redemption;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Redemption\Presentation;
use App\Engine\Pool;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

final class PresentationTest extends DbTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
    }

    private function pool(array $map): Pool
    {
        $e = [];
        foreach ($map as $id => $q) {
            $e[] = ['prize_id' => $id, 'quantity' => $q];
        }

        return Pool::fromEntries($e);
    }

    /** @return array<string, array<string, mixed>> definiciones de premios como las entiende el cliente */
    private function defs(): array
    {
        return [
            'none' => ['id' => 'none', 'type' => 'none', 'label' => 'Sin premio'],
            'p10k' => ['id' => 'p10k', 'type' => 'money', 'label' => '$ 10.000', 'amount' => 10000],
            'p50k' => ['id' => 'p50k', 'type' => 'money', 'label' => '$ 50.000', 'amount' => 50000],
        ];
    }

    private function rejects(string $campaign, string $type, array $config, Pool $pool, string $fragment): void
    {
        try {
            Presentation::assertRepresentable($campaign, $type, $config, $pool);
            $this->fail("debía rechazar (esperaba «$fragment»)");
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    // --- Validación de la ruleta ---------------------------------------------------------------------------------------

    public function test_una_ruleta_valida_se_acepta(): void
    {
        $c = $this->f->campaign();
        Presentation::assertRepresentable($c, 'roulette', Fixtures::gameConfig('roulette'), $this->pool(['none' => 5, 'p10k' => 3, 'p50k' => 1]));
        $this->addToAssertionCount(1);
    }

    public function test_la_ruleta_debe_tener_un_segmento_para_cada_premio_del_pool(): void
    {
        $c = $this->f->campaign();
        $cfg = ['segments' => ['none', 'p10k', 'none', 'p10k']]; // falta p50k
        $this->rejects($c, 'roulette', $cfg, $this->pool(['none' => 5, 'p50k' => 1]), 'ningún segmento para: p50k');
    }

    public function test_un_premio_con_cantidad_cero_no_exige_segmento(): void
    {
        $c = $this->f->campaign();
        Presentation::assertRepresentable($c, 'roulette', ['segments' => ['none', 'p10k', 'none', 'p10k']], $this->pool(['none' => 5, 'p10k' => 2, 'p50k' => 0]));
        $this->addToAssertionCount(1);
    }

    public function test_la_ruleta_exige_de_4_a_12_segmentos_de_premios_que_existen(): void
    {
        $c = $this->f->campaign();
        $pool = $this->pool(['none' => 1]);
        $this->rejects($c, 'roulette', ['segments' => ['none', 'none', 'none']], $pool, 'entre 4 y 12');
        $this->rejects($c, 'roulette', ['segments' => array_fill(0, 13, 'none')], $pool, 'entre 4 y 12');
        $this->rejects($c, 'roulette', [], $pool, 'entre 4 y 12');
        $this->rejects($c, 'roulette', ['segments' => ['none', 'none', 'none', 'fantasma']], $pool, 'no define');
        $this->rejects($c, 'roulette', ['segments' => ['none', 'none', 'none', 7]], $pool, 'no define');
        $this->rejects($c, 'roulette', ['segments' => ['a' => 'none', 'b' => 'none', 'c' => 'none', 'd' => 'none']], $pool, 'entre 4 y 12');
    }

    // --- Validación del raspa --------------------------------------------------------------------------------------------

    public function test_un_raspa_valido_se_acepta_con_los_valores_por_defecto(): void
    {
        $c = $this->f->campaign('scratch');
        Presentation::assertRepresentable($c, 'scratch', [], $this->pool(['none' => 1]));
        Presentation::assertRepresentable($c, 'scratch', ['rows' => 5, 'cols' => 5, 'symbols' => array_map(fn ($i) => "s$i", range(1, 13))], $this->pool(['none' => 1]));
        $this->addToAssertionCount(2);
    }

    public function test_el_raspa_rechaza_tamanos_y_simbolos_inviables(): void
    {
        $c = $this->f->campaign('scratch');
        $pool = $this->pool(['none' => 1]);
        $this->rejects($c, 'scratch', ['rows' => 1], $pool, 'de 2 a 5');
        $this->rejects($c, 'scratch', ['cols' => 6], $pool, 'de 2 a 5');
        $this->rejects($c, 'scratch', ['rows' => '3'], $pool, 'de 2 a 5');
        $this->rejects($c, 'scratch', ['symbols' => ['a', 'a', 'b', 'c', 'd']], $pool, 'distintos');
        $this->rejects($c, 'scratch', ['symbols' => ['a', '', 'b', 'c', 'd']], $pool, 'distintos');
        $this->rejects($c, 'scratch', ['symbols' => ['star', 'p10k', 'a', 'b', 'c']], $pool, 'coincide con un premio');
        // 3x3 = 9 casillas: sin premio cada símbolo entra como máximo 2 veces => hacen falta al menos 5 símbolos.
        $this->rejects($c, 'scratch', ['symbols' => ['a', 'b', 'c', 'd']], $pool, 'al menos 5 símbolos');
        $this->rejects($c, 'scratch', ['rows' => 5, 'cols' => 5], $pool, 'al menos 13 símbolos');
    }

    public function test_un_tipo_de_juego_desconocido_se_rechaza(): void
    {
        $c = $this->f->campaign();
        $this->rejects($c, 'dados', [], $this->pool(['none' => 1]), 'No hay presentación');
    }

    public function test_comprometer_un_lote_cuya_campania_no_puede_representar_sus_premios_falla_y_no_deja_rastro(): void
    {
        $c = $this->f->campaign();
        DB::table('campaigns')->where('id', $c)->update(['game_config' => json_encode(['segments' => ['none', 'p10k', 'none', 'p10k']])]);
        $batch = $this->f->lifecycle()->createDraft($c, 'L', Fixtures::pool(), 'ana'); // pool con p50k, que la ruleta no tiene
        $this->f->addCodes($batch, 10);

        try {
            $this->f->lifecycle()->commit($batch, 'ana');
            $this->fail('debía rechazar');
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString('p50k', $e->getMessage());
        }
        $this->assertSame('draft', $this->f->batchRow($batch)->state);
        $this->assertSame(0, DB::table('audit_log')->where('action', 'batch.committed')->count());
    }

    // --- Construcción: ruleta --------------------------------------------------------------------------------------------

    public function test_ruleta_elige_un_segmento_que_muestra_el_premio_y_lo_elige_entre_todos_los_que_lo_tienen(): void
    {
        $cfg = ['segments' => ['none', 'p10k', 'none', 'p50k', 'none'], 'durationMs' => 3000, 'fullTurns' => 4, 'otro' => 'se ignora'];
        $seen = [];
        // "random" que recorre todos los valores posibles: se comprueba que cada segmento candidato es alcanzable.
        for ($pick = 0; $pick < 3; $pick++) {
            $p = new Presentation(fn (int $min, int $max): int => min($pick, $max));
            $built = $p->build('roulette', $cfg, $this->defs() + ['x' => $this->defs()['none']], 'none');
            $seen[] = $built['gameResult']['segmentIndex'];
            $this->assertSame('none', $cfg['segments'][$built['gameResult']['segmentIndex']]);
        }
        $this->assertSame([0, 2, 4], $seen, 'los tres segmentos "sin premio" son alcanzables');
    }

    public function test_ruleta_entrega_los_segmentos_como_premios_completos_y_solo_los_parametros_conocidos(): void
    {
        $cfg = ['segments' => ['none', 'p10k', 'none', 'p50k'], 'durationMs' => 3000, 'fullTurns' => 4, 'otro' => 'se ignora'];
        $built = (new Presentation)->build('roulette', $cfg, $this->defs(), 'p10k');

        $this->assertSame(1, $built['gameResult']['segmentIndex']);
        $this->assertSame($this->defs()['p10k'], $built['gameConfig']['segments'][1]);
        $this->assertCount(4, $built['gameConfig']['segments']);
        $this->assertSame(3000, $built['gameConfig']['durationMs']);
        $this->assertSame(4, $built['gameConfig']['fullTurns']);
        $this->assertArrayNotHasKey('otro', $built['gameConfig'], 'no se filtra configuración interna al cliente');
    }

    public function test_ruleta_con_un_premio_que_no_esta_falla_con_claridad(): void
    {
        $this->expectException(\LogicException::class);
        (new Presentation)->build('roulette', ['segments' => ['none', 'none', 'none', 'none']], $this->defs(), 'p50k');
    }

    // --- Construcción: raspa (propiedades con muchas repeticiones y la fuente real de azar) ---------------------------------

    public function test_raspa_la_cuadricula_siempre_cumple_las_reglas_en_todos_los_tamanos(): void
    {
        $symbols = array_map(fn ($i) => "s$i", range(1, 13));
        foreach ([[2, 2], [2, 3], [3, 3], [3, 4], [4, 4], [5, 5]] as [$rows, $cols]) {
            $cells = $rows * $cols;
            $cfg = ['rows' => $rows, 'cols' => $cols, 'symbols' => $symbols];
            for ($i = 0; $i < 300; $i++) {
                $win = (new Presentation)->build('scratch', $cfg, $this->defs(), 'p10k')['gameResult']['grid'];
                $lose = (new Presentation)->build('scratch', $cfg, $this->defs(), 'none')['gameResult']['grid'];

                $this->assertCount($cells, $win);
                $this->assertCount($cells, $lose);

                $winCounts = array_count_values($win);
                $this->assertSame(3, $winCounts['p10k'], "{$rows}x{$cols}: exactamente 3 símbolos del premio");
                unset($winCounts['p10k']);
                $this->assertLessThan(3, max($winCounts ?: [0]), "{$rows}x{$cols}: el relleno nunca forma otro trío");

                $this->assertNotContains('none', $lose, 'sin premio no muestra símbolos de premio');
                $this->assertLessThan(3, max(array_count_values($lose)), "{$rows}x{$cols}: sin premio nada se repite 3 veces");
            }
        }
    }

    public function test_raspa_solo_usa_simbolos_de_relleno_y_el_premio(): void
    {
        $cfg = ['rows' => 3, 'cols' => 3, 'symbols' => ['a', 'b', 'c', 'd', 'e']];
        $grid = (new Presentation)->build('scratch', $cfg, $this->defs(), 'p50k')['gameResult']['grid'];
        $this->assertSame([], array_values(array_diff($grid, ['a', 'b', 'c', 'd', 'e', 'p50k'])));
    }

    public function test_raspa_las_posiciones_del_premio_varian(): void
    {
        $positions = [];
        for ($i = 0; $i < 400; $i++) {
            $grid = (new Presentation)->build('scratch', ['rows' => 3, 'cols' => 3], $this->defs(), 'p10k')['gameResult']['grid'];
            foreach (array_keys($grid, 'p10k', true) as $pos) {
                $positions[$pos] = ($positions[$pos] ?? 0) + 1;
            }
        }
        $this->assertCount(9, $positions, 'el premio aparece en las 9 posiciones a lo largo de 400 jugadas');
    }

    public function test_raspa_entrega_filas_columnas_y_parametros_conocidos(): void
    {
        $built = (new Presentation)->build('scratch', ['rows' => 3, 'cols' => 3, 'revealThreshold' => 0.5, 'brushWidth' => 30, 'secreto' => 'x'], $this->defs(), 'none');
        $this->assertEquals(['rows' => 3, 'cols' => 3, 'revealThreshold' => 0.5, 'brushWidth' => 30], $built['gameConfig']); // el orden de las claves no importa
    }

    public function test_raspa_sin_simbolos_suficientes_falla_con_claridad(): void
    {
        $this->expectException(\LogicException::class);
        (new Presentation)->build('scratch', ['rows' => 3, 'cols' => 3, 'symbols' => ['a', 'b']], $this->defs(), 'none');
    }

    public function test_un_tipo_de_juego_sin_presentacion_falla_al_construir(): void
    {
        $this->expectException(\LogicException::class);
        (new Presentation)->build('dados', [], $this->defs(), 'none');
    }

    // --- Reglas de campaña congeladas -------------------------------------------------------------------------------------

    public function test_las_reglas_de_juego_de_una_campania_se_congelan_al_comprometer_un_lote(): void
    {
        $c = $this->f->campaign();
        $batch = $this->f->draftWithCodes();
        // draftWithCodes usa OTRA campaña; armamos un lote en esta:
        $own = $this->f->lifecycle()->createDraft($c, 'L', Fixtures::pool(), 'ana');
        $this->f->addCodes($own, 10);

        // En borrador todavía se puede cambiar todo.
        $this->assertSame(1, DB::update("UPDATE campaigns SET game_config = '{\"segments\": []}'::jsonb WHERE id = ?", [$c]));
        DB::update('UPDATE campaigns SET game_config = ?::jsonb WHERE id = ?', [json_encode(Fixtures::gameConfig('roulette')), $c]);

        $this->f->lifecycle()->commit($own, 'ana');

        foreach (["game_config = '{}'::jsonb", "game_type = 'scratch'", "used_code_behavior = 'showPrevious'"] as $set) {
            $this->assertDbRejects(
                fn () => DB::update("UPDATE campaigns SET $set WHERE id = ?", [$c]),
                'sus reglas de juego son inmutables',
            );
        }
        // El tema y el título sí se pueden ajustar.
        $this->assertSame(1, DB::update("UPDATE campaigns SET title = 'Nuevo título', theme = '{\"primary\": \"#112233\"}'::jsonb WHERE id = ?", [$c]));
        $this->assertNotNull($batch);
    }
}
