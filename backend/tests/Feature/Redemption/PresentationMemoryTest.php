<?php

namespace Tests\Feature\Redemption;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Redemption\Presentation;
use App\Engine\Pool;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/** Concéntrese (SPEC-004): la disposición de las cartas siempre es coherente con el premio ya decidido. */
final class PresentationMemoryTest extends DbTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
    }

    private function pool(): Pool
    {
        return Pool::fromEntries([['prize_id' => 'none', 'quantity' => 5], ['prize_id' => 'p10k', 'quantity' => 3], ['prize_id' => 'p50k', 'quantity' => 1]]);
    }

    private function defs(): array
    {
        return [
            'none' => ['id' => 'none', 'type' => 'none', 'label' => 'Sin premio'],
            'p10k' => ['id' => 'p10k', 'type' => 'money', 'label' => '$ 10.000', 'amount' => 10000],
            'p50k' => ['id' => 'p50k', 'type' => 'money', 'label' => '$ 50.000', 'amount' => 50000],
        ];
    }

    private function rejects(array $config, string $fragment): void
    {
        try {
            Presentation::assertRepresentable($this->f->campaign('memory'), 'memory', $config, $this->pool());
            $this->fail("debía rechazar (esperaba «$fragment»)");
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    // --- Validación -------------------------------------------------------------------------------------------------

    public function test_una_configuracion_valida_y_la_de_por_defecto_se_aceptan(): void
    {
        $c = $this->f->campaign('memory');
        Presentation::assertRepresentable($c, 'memory', Fixtures::gameConfig('memory'), $this->pool());
        Presentation::assertRepresentable($c, 'memory', [], $this->pool());
        $this->addToAssertionCount(2);
    }

    public function test_rechaza_parametros_fuera_de_rango_o_de_tipo(): void
    {
        $this->rejects(['pairs' => 2], '3 a 8 parejas');
        $this->rejects(['pairs' => 9], '3 a 8 parejas');
        $this->rejects(['pairs' => '6'], '3 a 8 parejas');
        $this->rejects(['columns' => 1], '2 a 6 columnas');
        $this->rejects(['columns' => 7], '2 a 6 columnas');
        $this->rejects(['flipBackMs' => 100], 'flipBackMs');
        $this->rejects(['flipBackMs' => 9000], 'flipBackMs');
    }

    public function test_rechaza_simbolos_inviables(): void
    {
        $this->rejects(['symbols' => ['a', 'b', 'c', 'd', 'e']], 'al menos 6 símbolos');
        $this->rejects(['pairs' => 3, 'symbols' => ['a', 'a', 'b']], 'textos distintos');
        $this->rejects(['pairs' => 3, 'symbols' => ['a', '', 'b']], 'textos distintos');
        $this->rejects(['pairs' => 3, 'symbols' => ['a', 'p10k', 'b']], 'coincide con un premio (p10k)');
    }

    // --- Construcción (propiedades) ---------------------------------------------------------------------------------

    /** @return array{cards: list<string>} */
    private function build(string $prize, array $cfg = []): array
    {
        return (new Presentation)->build('memory', $cfg + Fixtures::gameConfig('memory'), $this->defs(), $prize);
    }

    public function test_con_premio_hay_exactamente_una_pareja_premio_y_cada_simbolo_va_dos_veces(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $cards = $this->build('p50k')['gameResult']['cards'];
            $counts = array_count_values($cards);
            $this->assertCount(12, $cards);
            $this->assertSame(2, $counts['p50k'], 'la pareja premio');
            $this->assertSame([2], array_values(array_unique($counts)), 'todo símbolo va exactamente dos veces');
            $this->assertCount(6, $counts, '6 parejas, una de ellas la del premio');
        }
    }

    public function test_sin_premio_no_hay_ninguna_pareja_de_premio(): void
    {
        $prizes = ['none', 'p10k', 'p50k', 'p100k'];
        for ($i = 0; $i < 300; $i++) {
            $cards = $this->build('none')['gameResult']['cards'];
            $this->assertCount(12, $cards);
            $this->assertSame([], array_intersect($cards, $prizes), 'ningún relleno parece un premio');
            $this->assertSame([2], array_values(array_unique(array_count_values($cards))));
            $this->assertCount(6, array_unique($cards));
        }
    }

    public function test_respeta_el_numero_de_parejas_y_entrega_solo_los_parametros_conocidos(): void
    {
        $r = $this->build('p10k', ['pairs' => 8, 'columns' => 4, 'flipBackMs' => 1200]);
        $this->assertCount(16, $r['gameResult']['cards']);
        $this->assertSame(['pairs' => 8, 'columns' => 4, 'flipBackMs' => 1200], $r['gameConfig']);
        $this->assertSame(['cards'], array_keys($r['gameResult']));
    }

    public function test_el_orden_de_las_cartas_varia(): void
    {
        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $seen[implode(',', $this->build('p10k')['gameResult']['cards'])] = true;
        }
        $this->assertGreaterThan(30, count($seen), 'la disposición se elige al azar');
    }

    public function test_con_simbolos_justos_sin_premio_funciona_y_con_premio_sobran(): void
    {
        $cfg = ['pairs' => 3, 'symbols' => ['a', 'b', 'c']];
        $none = $this->build('none', $cfg)['gameResult']['cards'];
        $this->assertEqualsCanonicalizing(['a', 'a', 'b', 'b', 'c', 'c'], $none);
        $win = $this->build('p10k', $cfg)['gameResult']['cards'];
        $this->assertSame(2, array_count_values($win)['p10k']);
        $this->assertCount(6, $win);
    }

    public function test_sin_simbolos_suficientes_falla_con_claridad(): void
    {
        $this->expectException(\LogicException::class);
        $this->build('none', ['pairs' => 4, 'symbols' => ['a', 'b']]);
    }
}
