<?php

namespace Tests\Feature\Redemption;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Redemption\Poker;
use App\Domain\Redemption\Presentation;
use App\Engine\Pool;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/** Póker del crupier (SPEC-005 §5): las 7 cartas siempre dan la mejor mano del premio ya decidido. Se cruza con un evaluador INDEPENDIENTE. */
final class PresentationPokerTest extends DbTestCase
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
        return $override + Fixtures::gameConfig('poker');
    }

    private function build(string $prize, array $override = [], ?callable $rand = null): array
    {
        return (new Presentation($rand))->build('poker', $this->cfg($override), $this->defs(), $prize);
    }

    private function rejects(array $config, string $fragment, ?Pool $pool = null): void
    {
        try {
            Presentation::assertRepresentable($this->f->campaign('poker'), 'poker', $config + Fixtures::gameConfig('poker'), $pool ?? $this->pool());
            $this->fail("debía rechazar (esperaba «$fragment»)");
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    // --- Evaluador independiente (otro algoritmo: cuenta rangos y palos de las 7 cartas, sin armar manos de 5) ------------

    /** @param list<string> $cards p. ej. ['AS','KD',…] */
    private function category(array $cards): string
    {
        $ranks = [];
        $bySuit = [];
        foreach ($cards as $c) {
            $r = strpos(Poker::RANKS, $c[0]);
            $ranks[] = $r;
            $bySuit[$c[1]][] = $r;
        }
        $counts = array_count_values($ranks);
        $hasStraight = function (array $rs): bool {
            $set = array_flip(array_unique($rs));
            if (isset($set[12])) {
                $set[-1] = true; // el As también vale 1
            }
            for ($low = -1; $low <= 8; $low++) {
                if (isset($set[$low], $set[$low + 1], $set[$low + 2], $set[$low + 3], $set[$low + 4])) {
                    return true;
                }
            }

            return false;
        };
        foreach ($bySuit as $rs) {
            if (count($rs) >= 5 && $hasStraight($rs)) {
                return 'straight_flush';
            }
        }
        $quads = count(array_filter($counts, fn ($n) => $n === 4));
        $trips = count(array_filter($counts, fn ($n) => $n === 3));
        $pairs = count(array_filter($counts, fn ($n) => $n === 2));
        if ($quads >= 1) {
            return 'four_of_a_kind';
        }
        if ($trips >= 2 || ($trips >= 1 && $pairs >= 1)) {
            return 'full_house';
        }
        foreach ($bySuit as $rs) {
            if (count($rs) >= 5) {
                return 'flush';
            }
        }
        if ($hasStraight($ranks)) {
            return 'straight';
        }
        if ($trips >= 1) {
            return 'three_of_a_kind';
        }
        if ($pairs >= 2) {
            return 'two_pair';
        }
        if ($pairs === 1) {
            return 'pair';
        }

        return 'high_card';
    }

    // --- Evaluador ----------------------------------------------------------------------------------------------------

    /** @param list<string> $names */
    private function ev(array $names): array
    {
        return Poker::evaluate(array_map(Poker::parse(...), $names));
    }

    public function test_manos_conocidas(): void
    {
        $known = [
            ['AS KS QS JS TS 2D 3C', 'straight_flush'],   // escalera real
            ['5H 4H 3H 2H AH KC QD', 'straight_flush'],   // la rueda de corazones
            ['9C 9D 9H 9S 2C 3D 7H', 'four_of_a_kind'],
            ['KC KD KH 2S 2C 7D 9H', 'full_house'],
            ['KC KD KH QS QC QD 9H', 'full_house'],        // dos tríos
            ['AH 9H 7H 4H 2H KC KD', 'flush'],
            ['5C 4D 3H 2S AC KD 9H', 'straight'],          // la rueda
            ['TC JD QH KS AC 2D 3H', 'straight'],          // de diez a as
            ['7C 7D 7H 2S 9C KD 4H', 'three_of_a_kind'],
            ['7C 7D 2H 2S 9C KD 4H', 'two_pair'],
            ['7C 7D 3H 2S 9C KD 4H', 'pair'],
            ['AC KD 9H 7S 5C 3D 2H', 'high_card'],
            ['2C 3D 4H 5S 7C 9D KH', 'high_card'],         // casi escalera: no cuenta
            ['AC 2C 3C 4C KD QD JD', 'flush' === 'x' ? 'x' : 'high_card'], // cuatro del color: no es color
        ];
        foreach ($known as [$hand, $expected]) {
            $this->assertSame($expected, $this->ev(explode(' ', $hand))['category'], $hand);
            $this->assertSame($expected, $this->category(explode(' ', $hand)), "independiente: $hand");
        }
    }

    public function test_la_mejor_mano_de_cinco_incluye_las_cartas_que_la_forman(): void
    {
        $e = $this->ev(explode(' ', 'KC KD KH 2S 2C 7D 9H'));
        $names = array_map(Poker::name(...), $e['best']);
        sort($names);
        $this->assertSame(['2C', '2S', 'KC', 'KD', 'KH'], $names);
        $e = $this->ev(explode(' ', '5C 4D 3H 2S AC KD KH'));
        $this->assertCount(5, $e['best']);
        $this->assertSame('straight', $e['category']);
    }

    public function test_el_evaluador_coincide_con_uno_independiente_en_decenas_de_miles_de_manos_al_azar(): void
    {
        mt_srand(2026);
        $seen = [];
        for ($i = 0; $i < 15000; $i++) {
            $deck = range(0, 51);
            shuffle($deck);
            $hand = array_map(Poker::name(...), array_slice($deck, 0, 7));
            $mine = $this->ev($hand)['category'];
            $this->assertSame($this->category($hand), $mine, implode(' ', $hand));
            $seen[$mine] = true;
        }
        $this->assertGreaterThanOrEqual(7, count($seen), 'salieron casi todas las categorías');
    }

    // --- Validación -------------------------------------------------------------------------------------------------

    public function test_la_configuracion_valida_se_acepta(): void
    {
        Presentation::assertRepresentable($this->f->campaign('poker'), 'poker', Fixtures::gameConfig('poker'), $this->pool());
        Presentation::assertRepresentable($this->f->campaign('poker'), 'poker', $this->cfg(['revealIntervalMs' => 400]), $this->pool());
        $this->addToAssertionCount(2);
    }

    public function test_rechaza_parametros_y_categorias_invalidos(): void
    {
        $this->rejects(['revealIntervalMs' => 100], 'revealIntervalMs');
        $this->rejects(['hands' => []], 'game_config.hands');
        $this->rejects(['hands' => ['pair' => 'p10k']], 'no puede pagar');
        $this->rejects(['hands' => ['nada' => 'p10k']], 'no puede pagar');
        $this->rejects(['hands' => ['three_of_a_kind' => 'nope']], 'no existe en la campaña');
        $this->rejects(['hands' => ['three_of_a_kind' => 'none']], 'no vale nada');
    }

    public function test_no_se_permiten_saltos_entre_las_manos_que_pagan(): void
    {
        $this->rejects(['hands' => ['flush' => 'p10k', 'four_of_a_kind' => 'p50k', 'straight_flush' => 'p100k']], 'faltan full_house');
        $this->rejects(['hands' => ['three_of_a_kind' => 'p10k']], 'faltan straight');
    }

    public function test_un_premio_ganador_del_pool_sin_mano_se_rechaza_pero_uno_con_cantidad_cero_no(): void
    {
        $solo10 = ['three_of_a_kind' => 'p10k', 'straight' => 'p10k', 'flush' => 'p10k', 'full_house' => 'p10k', 'four_of_a_kind' => 'p10k', 'straight_flush' => 'p10k'];
        $this->rejects(['hands' => $solo10], 'p50k');
        $pool = Pool::fromEntries([['prize_id' => 'none', 'quantity' => 5], ['prize_id' => 'p10k', 'quantity' => 3], ['prize_id' => 'p50k', 'quantity' => 0]]);
        Presentation::assertRepresentable($this->f->campaign('poker'), 'poker', $this->cfg(['hands' => $solo10]), $pool);
        $this->addToAssertionCount(1);
    }

    // --- Construcción -----------------------------------------------------------------------------------------------

    private function assertHand(array $r, array $hands, string $prize, string $label): void
    {
        $res = $r['gameResult'];
        $cards = [...$res['hole'], ...$res['board']];
        $this->assertCount(2, $res['hole'], $label);
        $this->assertCount(5, $res['board'], $label);
        $this->assertCount(7, array_unique($cards), "$label: cartas repetidas");
        foreach ($cards as $c) {
            $this->assertMatchesRegularExpression('/^[2-9TJQKA][SHDC]$/', $c, $label);
        }
        $cat = $this->category($cards);
        $this->assertSame($cat, $res['category'], "$label: categoría");
        $this->assertSame($prize === 'none' ? false : true, isset($hands[$cat]), "$label: la mano $cat ".($prize === 'none' ? 'no debe pagar' : 'debe pagar'));
        if ($prize === 'none') {
            $this->assertNull($res['hand'], $label);
        } else {
            $this->assertSame($prize, $hands[$cat], "$label: la mano $cat paga otro premio");
            $this->assertSame($cat, $res['hand'], $label);
        }
        // Lo que se va viendo: mejor mano con 5, 6 y 7 cartas.
        $this->assertSame([
            $this->category([...$res['hole'], ...array_slice($res['board'], 0, 3)]),
            $this->category([...$res['hole'], ...array_slice($res['board'], 0, 4)]),
            $cat,
        ], $res['stages'], "$label: etapas");
        // Las 5 cartas resaltadas son de las 7 y forman por sí solas esa categoría.
        $this->assertCount(5, $res['best'], $label);
        $this->assertSame([], array_diff($res['best'], $cards), "$label: best fuera de las 7");
        $this->assertSame($cat, $this->category($res['best']), "$label: las 5 cartas de la mano");
    }

    public function test_cada_premio_y_sin_premio_producen_una_mano_coherente_con_miles_de_semillas(): void
    {
        $hands = Fixtures::gameConfig('poker')['hands'];
        foreach (['p10k', 'p50k', 'p100k', 'none'] as $prize) {
            for ($i = 0; $i < 400; $i++) {
                $this->assertHand($this->build($prize), $hands, $prize, "$prize #$i");
            }
        }
    }

    public function test_cada_categoria_que_paga_sale_con_su_premio(): void
    {
        $hands = Fixtures::gameConfig('poker')['hands'];
        $seen = [];
        for ($i = 0; $i < 800; $i++) {
            $seen[$this->build('p10k')['gameResult']['hand']] = true;
            $seen[$this->build('p100k')['gameResult']['hand']] = true;
        }
        ksort($seen);
        $this->assertSame(['flush', 'four_of_a_kind', 'straight', 'straight_flush', 'three_of_a_kind'], array_keys($seen), 'salen todas las categorías de esos premios');
    }

    public function test_con_otras_tablas_tambien_es_coherente(): void
    {
        foreach ([
            ['full_house' => 'p50k', 'four_of_a_kind' => 'p100k', 'straight_flush' => 'p100k'],
            ['flush' => 'p10k', 'full_house' => 'p10k', 'four_of_a_kind' => 'p50k', 'straight_flush' => 'p100k'],
            ['straight_flush' => 'p100k'],
        ] as $hands) {
            $cfg = ['hands' => $hands];
            foreach (array_unique(array_values($hands)) as $prize) {
                for ($i = 0; $i < 150; $i++) {
                    $this->assertHand($this->build($prize, $cfg), $hands, $prize, json_encode($hands)." $prize #$i");
                }
            }
            for ($i = 0; $i < 150; $i++) {
                $this->assertHand($this->build('none', $cfg), $hands, 'none', json_encode($hands)." none #$i");
            }
        }
    }

    public function test_el_gameConfig_trae_la_tabla_de_la_mejor_mano_a_la_peor_con_los_premios_completos(): void
    {
        $c = $this->build('p10k')['gameConfig'];
        $this->assertSame(900, $c['revealIntervalMs']);
        $this->assertSame(['straight_flush', 'four_of_a_kind', 'full_house', 'flush', 'straight', 'three_of_a_kind'], array_column($c['legend'], 'hand'));
        $this->assertSame($this->defs()['p100k'], $c['legend'][0]['prize']);
    }

    public function test_con_la_misma_semilla_el_resultado_es_reproducible(): void
    {
        $mk = function (int $seed) {
            mt_srand($seed);

            return $this->build('p50k', [], fn (int $a, int $b) => mt_rand($a, $b));
        };
        $this->assertSame($mk(3), $mk(3));
        $this->assertNotSame($mk(3), $mk(4));
    }
}
