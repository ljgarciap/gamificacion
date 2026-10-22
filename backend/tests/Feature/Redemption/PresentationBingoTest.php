<?php

namespace Tests\Feature\Redemption;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Redemption\Bingo;
use App\Domain\Redemption\Presentation;
use App\Engine\Pool;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/** Bingo en vivo (SPEC-005 §4): cartón y bolas siempre coherentes con el premio ya decidido. Se comprueba con un evaluador INDEPENDIENTE del del código. */
final class PresentationBingoTest extends DbTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
    }

    private function pool(): Pool
    {
        return Pool::fromEntries([['prize_id' => 'none', 'quantity' => 5], ['prize_id' => 'p10k', 'quantity' => 3], ['prize_id' => 'p50k', 'quantity' => 2], ['prize_id' => 'p100k', 'quantity' => 1]]);
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

    private function cfg(array $override = []): array
    {
        return $override + Fixtures::gameConfig('bingo');
    }

    private function build(string $prize, array $override = [], ?callable $rand = null): array
    {
        return (new Presentation($rand))->build('bingo', $this->cfg($override), $this->defs(), $prize);
    }

    private function rejects(array $config, string $fragment, ?Pool $pool = null): void
    {
        try {
            Presentation::assertRepresentable($this->f->campaign('bingo'), 'bingo', $config + Fixtures::gameConfig('bingo'), $pool ?? $this->pool());
            $this->fail("debía rechazar (esperaba «$fragment»)");
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    // --- Validación -------------------------------------------------------------------------------------------------

    public function test_la_configuracion_valida_se_acepta(): void
    {
        Presentation::assertRepresentable($this->f->campaign('bingo'), 'bingo', Fixtures::gameConfig('bingo'), $this->pool());
        Presentation::assertRepresentable($this->f->campaign('bingo'), 'bingo', $this->cfg(['draws' => 60, 'drawIntervalMs' => 400, 'minWinDraw' => 60]), $this->pool());
        $this->addToAssertionCount(2);
    }

    public function test_exige_patrones_y_rechaza_parametros_invalidos(): void
    {
        $this->rejects(['patterns' => []], 'game_config.patterns');
        $this->rejects(['patterns' => 'line'], 'game_config.patterns');
        $this->rejects(['draws' => 23], '24 a 60 bolas');
        $this->rejects(['draws' => 61], '24 a 60 bolas');
        $this->rejects(['draws' => '30'], '24 a 60 bolas');
        $this->rejects(['drawIntervalMs' => 100], 'drawIntervalMs');
        $this->rejects(['minWinDraw' => 4], 'minWinDraw');
        $this->rejects(['draws' => 30, 'minWinDraw' => 31], 'minWinDraw');
    }

    public function test_rechaza_patrones_premios_y_formas_ambiguas(): void
    {
        $this->rejects(['patterns' => [['pattern' => 'x', 'prize' => 'p10k']]], 'desconocido');
        $this->rejects(['patterns' => [['pattern' => 'line', 'prize' => 'nada']]], 'no existe en la campaña');
        $this->rejects(['patterns' => [['pattern' => 'line', 'prize' => 'none']]], 'no vale nada');
        $this->rejects(['patterns' => [['pattern' => 'line', 'prize' => 'p10k'], ['pattern' => 'line', 'prize' => 'p50k']]], 'repetido');
        $this->rejects(['patterns' => [['pattern' => 'line', 'prize' => 'p10k'], ['pattern' => 'corners', 'prize' => 'p10k']]], 'dos patrones');
        $this->rejects(['patterns' => [['pattern' => 'line']]], 'debe ser');
    }

    public function test_el_carton_lleno_solo_puede_ir_primero(): void
    {
        $this->rejects(['patterns' => [['pattern' => 'line', 'prize' => 'p10k'], ['pattern' => 'full', 'prize' => 'p100k'], ['pattern' => 'corners', 'prize' => 'p50k']]], 'cartón lleno debe ir primero');
        $this->rejects(['patterns' => [['pattern' => 'corners', 'prize' => 'p50k'], ['pattern' => 'full', 'prize' => 'p100k'], ['pattern' => 'line', 'prize' => 'p10k']]], 'cartón lleno debe ir primero');
    }

    public function test_un_premio_ganador_del_pool_sin_patron_se_rechaza_pero_uno_con_cantidad_cero_no(): void
    {
        $this->rejects(['patterns' => [['pattern' => 'line', 'prize' => 'p10k']]], 'p50k');
        $pool = Pool::fromEntries([['prize_id' => 'none', 'quantity' => 5], ['prize_id' => 'p10k', 'quantity' => 3], ['prize_id' => 'p50k', 'quantity' => 0]]);
        Presentation::assertRepresentable($this->f->campaign('bingo'), 'bingo', $this->cfg(['patterns' => [['pattern' => 'line', 'prize' => 'p10k']]]), $pool);
        $this->addToAssertionCount(1);
    }

    public function test_el_patron_cartón_lleno_necesita_bolas_suficientes(): void
    {
        $this->assertSame(24, Bingo::requiredCells('full'));
        $this->assertSame(4, Bingo::requiredCells('corners'));
        $this->assertSame(4, Bingo::requiredCells('line'), 'una línea por el centro libre necesita 4');
    }

    // --- Evaluador independiente ------------------------------------------------------------------------------------

    /** @return array<string, list<list<array{0: int, 1: int}>>> formas de cada patrón, como coordenadas [fila, columna] */
    private function shapes(): array
    {
        $rows = $cols = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $c = [];
            for ($j = 0; $j < 5; $j++) {
                $r[] = [$i, $j];
                $c[] = [$j, $i];
            }
            $rows[] = $r;
            $cols[] = $c;
        }
        $d1 = $d2 = $all = [];
        for ($i = 0; $i < 5; $i++) {
            $d1[] = [$i, $i];
            $d2[] = [$i, 4 - $i];
            for ($j = 0; $j < 5; $j++) {
                $all[] = [$i, $j];
            }
        }

        return ['line' => [...$rows, ...$cols, $d1, $d2], 'corners' => [[[0, 0], [0, 4], [4, 0], [4, 4]]], 'full' => [$all]];
    }

    /** @return list<array{at: int, pattern: string}> eventos que ve un evaluador independiente */
    private function independentEvents(array $card, array $balls, array $order): array
    {
        $pos = [];
        foreach ($card as $r => $row) {
            foreach ($row as $c => $n) {
                if ($n !== null) {
                    $pos[$n] = [$r, $c];
                }
            }
        }
        $marked = ['2,2' => true];
        $done = $events = [];
        foreach ($balls as $i => $b) {
            if (isset($pos[$b])) {
                $marked[$pos[$b][0].','.$pos[$b][1]] = true;
            }
            foreach ($order as $pattern) {
                if (isset($done[$pattern])) {
                    continue;
                }
                foreach ($this->shapes()[$pattern] as $shape) {
                    if (count(array_filter($shape, fn ($p) => isset($marked[$p[0].','.$p[1]]))) === count($shape)) {
                        $done[$pattern] = true;
                        $events[] = ['at' => $i + 1, 'pattern' => $pattern];
                        break;
                    }
                }
            }
        }

        return $events;
    }

    private function assertGame(array $r, array $cfg, string $prize, string $label): void
    {
        $result = $r['gameResult'];
        $card = $result['card'];
        $balls = $result['balls'];
        $order = array_column($cfg['patterns'], 'pattern');
        $draws = $cfg['draws'] ?? 30;
        $target = null;
        foreach ($cfg['patterns'] as $p) {
            if ($p['prize'] === $prize) {
                $target = $p['pattern'];
            }
        }

        // Cartón: 5×5, cada columna en su rango sin repetidos, centro libre.
        $this->assertCount(5, $card, $label);
        $seen = [];
        foreach ($card as $row => $cells) {
            $this->assertCount(5, $cells, $label);
            foreach ($cells as $col => $n) {
                if ($row === 2 && $col === 2) {
                    $this->assertNull($n, "$label centro libre");

                    continue;
                }
                $this->assertGreaterThanOrEqual($col * 15 + 1, $n, "$label rango de la columna $col");
                $this->assertLessThanOrEqual($col * 15 + 15, $n, "$label rango de la columna $col");
                $this->assertArrayNotHasKey($n, $seen, "$label número repetido en el cartón");
                $seen[$n] = true;
            }
        }
        // Bolas: `draws`, únicas, de 1 a 75.
        $this->assertCount($draws, $balls, $label);
        $this->assertSame($balls, array_values(array_unique($balls)), "$label bolas únicas");
        $this->assertSame([], array_filter($balls, fn ($b) => ! is_int($b) || $b < 1 || $b > 75), "$label rango de las bolas");

        // Lo que dice el servidor coincide con un evaluador independiente.
        $events = $this->independentEvents($card, $balls, $order);
        $this->assertSame($events, array_map(fn ($e) => ['at' => $e['at'], 'pattern' => $e['pattern']], $result['events']), "$label eventos");

        if ($target === null) { // sin premio
            $this->assertSame([], $events, "$label no se completa ningún patrón que pague");
            $this->assertNull($result['winAt']);
            $this->assertNull($result['pattern']);
            $this->assertNull($result['cells']);
            $this->assertSame($draws, $result['endAt']);

            return;
        }
        $rank = array_search($target, $order, true);
        foreach ($events as $e) {
            $this->assertGreaterThanOrEqual($rank, array_search($e['pattern'], $order, true), "$label: se completó un patrón de MAYOR prioridad ({$e['pattern']})");
        }
        $mine = array_values(array_filter($events, fn ($e) => $e['pattern'] === $target));
        $this->assertCount(1, $mine, "$label: el patrón del premio se completa");
        $this->assertSame($mine[0]['at'], $result['winAt'], $label);
        $this->assertSame($target, $result['pattern'], $label);
        $this->assertSame($rank === 0 ? $result['winAt'] : $draws, $result['endAt'], "$label: la partida termina en winAt solo con el patrón mayor");
        $this->assertGreaterThanOrEqual($cfg['minWinDraw'] ?? 10, $result['winAt'], "$label: no gana demasiado pronto");
        // Las casillas resaltadas son una forma del patrón y todas están marcadas en winAt.
        $this->assertContains($result['cells'], $this->shapes()[$target], "$label: forma del patrón");
        $pos = [];
        foreach ($card as $r => $row) {
            foreach ($row as $c => $n) {
                if ($n !== null) {
                    $pos[$n] = "$r,$c";
                }
            }
        }
        $marked = ['2,2' => true];
        foreach (array_slice($balls, 0, $result['winAt']) as $b) {
            if (isset($pos[$b])) {
                $marked[$pos[$b]] = true;
            }
        }
        foreach ($result['cells'] as [$r, $c]) {
            $this->assertArrayHasKey("$r,$c", $marked, "$label: casilla resaltada sin marcar");
        }
    }

    // --- Construcción -----------------------------------------------------------------------------------------------

    public function test_cada_premio_y_sin_premio_producen_una_partida_coherente_con_miles_de_semillas(): void
    {
        $cfg = $this->cfg();
        foreach (['p100k', 'p50k', 'p10k', 'none'] as $prize) {
            for ($i = 0; $i < 250; $i++) {
                $this->assertGame($this->build($prize), $cfg, $prize, "$prize #$i");
            }
        }
    }

    public function test_con_otras_prioridades_y_tamanos_tambien_es_coherente(): void
    {
        $variants = [
            ['draws' => 24, 'minWinDraw' => 24],
            ['draws' => 60, 'minWinDraw' => 5],
            ['draws' => 45, 'minWinDraw' => 20, 'patterns' => [['pattern' => 'line', 'prize' => 'p10k'], ['pattern' => 'corners', 'prize' => 'p50k']]],
            ['draws' => 40, 'patterns' => [['pattern' => 'full', 'prize' => 'p100k'], ['pattern' => 'line', 'prize' => 'p10k'], ['pattern' => 'corners', 'prize' => 'p50k']]],
            ['draws' => 50, 'patterns' => [['pattern' => 'full', 'prize' => 'p100k'], ['pattern' => 'corners', 'prize' => 'p50k']]],
        ];
        foreach ($variants as $v) {
            $cfg = $this->cfg($v);
            foreach ($cfg['patterns'] as $p) {
                for ($i = 0; $i < 80; $i++) {
                    $this->assertGame($this->build($p['prize'], $v), $cfg, $p['prize'], json_encode($v)." {$p['prize']} #$i");
                }
            }
            for ($i = 0; $i < 80; $i++) {
                $this->assertGame($this->build('none', $v), $cfg, 'none', json_encode($v)." none #$i");
            }
        }
    }

    public function test_el_gameConfig_trae_la_tabla_en_orden_de_prioridad_con_los_premios_completos(): void
    {
        $c = $this->build('p10k')['gameConfig'];
        $this->assertSame(30, $c['draws']);
        $this->assertSame(1200, $c['drawIntervalMs']);
        $this->assertSame(['full', 'corners', 'line'], array_column($c['legend'], 'pattern'));
        $this->assertSame($this->defs()['p100k'], $c['legend'][0]['prize']);
    }

    public function test_con_la_misma_semilla_el_resultado_es_reproducible(): void
    {
        $mk = function (int $seed) {
            mt_srand($seed);

            return $this->build('p50k', [], fn (int $a, int $b) => mt_rand($a, $b));
        };
        $this->assertSame($mk(7), $mk(7));
        $this->assertNotSame($mk(7), $mk(8));
    }
}
