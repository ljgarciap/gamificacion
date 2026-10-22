<?php

namespace Tests\Feature\Redemption;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Redemption\Presentation;
use App\Engine\Pool;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/** Bomba 8 (SPEC-005 §3): las caras en orden de volteo siempre son coherentes con el premio ya decidido. */
final class PresentationBombTest extends DbTestCase
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

    private function build(string $prize, array $cfg = []): array
    {
        return (new Presentation)->build('bomb', $cfg + Fixtures::gameConfig('bomb'), $this->defs(), $prize);
    }

    private function rejects(array $config, string $fragment): void
    {
        try {
            Presentation::assertRepresentable($this->f->campaign('bomb'), 'bomb', $config, $this->pool());
            $this->fail("debía rechazar (esperaba «$fragment»)");
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    // --- Validación -------------------------------------------------------------------------------------------------

    public function test_la_configuracion_valida_y_la_de_por_defecto_se_aceptan(): void
    {
        $c = $this->f->campaign('bomb');
        Presentation::assertRepresentable($c, 'bomb', Fixtures::gameConfig('bomb'), $this->pool());
        Presentation::assertRepresentable($c, 'bomb', [], $this->pool());
        Presentation::assertRepresentable($c, 'bomb', ['cards' => 16, 'columns' => 4, 'lateWindow' => 13], $this->pool());
        $this->addToAssertionCount(3);
    }

    public function test_rechaza_parametros_fuera_de_rango_o_de_tipo(): void
    {
        $this->rejects(['cards' => 8], '9 a 16 cartas');
        $this->rejects(['cards' => 17], '9 a 16 cartas');
        $this->rejects(['cards' => '12'], '9 a 16 cartas');
        $this->rejects(['columns' => 1], '2 a 5 columnas');
        $this->rejects(['columns' => 6], '2 a 5 columnas');
        $this->rejects(['lateWindow' => 0], 'lateWindow');
        $this->rejects(['cards' => 12, 'lateWindow' => 10], 'lateWindow');
    }

    public function test_rechaza_simbolos_inviables(): void
    {
        $this->rejects(['symbols' => ['a', 'a', 'b', 'c', 'd', 'e']], 'sin repetidos');
        $this->rejects(['symbols' => ['a', 'b', '8', 'c', 'd', 'e']], 'no puede ser «8»');
        $this->rejects(['symbols' => ['a', 'b', 'p10k', 'c', 'd', 'e']], 'coincide con un premio');
        $this->rejects(['symbols' => ['a', 'b', 'c']], 'al menos 6 símbolos');
        $this->rejects(['symbols' => ['a', 1, 'c', 'd', 'e', 'f']], 'texto no vacío');
    }

    // --- Construcción -----------------------------------------------------------------------------------------------

    public function test_con_premio_hay_exactamente_tres_ochos_y_el_tercero_cae_entre_las_ultimas_cartas(): void
    {
        foreach ([['cards' => 12, 'lateWindow' => 4], ['cards' => 9, 'lateWindow' => 1], ['cards' => 16, 'lateWindow' => 13]] as $cfg) {
            for ($i = 0; $i < 300; $i++) {
                $faces = $this->build('p50k', $cfg)['gameResult']['faces'];
                $eights = array_keys(array_filter($faces, fn ($f) => $f === '8'));
                $this->assertCount($cfg['cards'], $faces);
                $this->assertCount(3, $eights, 'exactamente tres «8»');
                $this->assertGreaterThanOrEqual($cfg['cards'] - $cfg['lateWindow'], $eights[2], 'el tercero va entre las últimas '.$cfg['lateWindow']);
                $this->assertLessThan($eights[2], $eights[1]);
            }
        }
    }

    public function test_sin_premio_nunca_hay_tres_ochos_y_se_ven_cero_uno_y_dos(): void
    {
        $seen = [];
        for ($i = 0; $i < 600; $i++) {
            $faces = $this->build('none')['gameResult']['faces'];
            $n = count(array_filter($faces, fn ($f) => $f === '8'));
            $this->assertLessThanOrEqual(2, $n);
            $seen[$n] = true;
        }
        ksort($seen);
        $this->assertSame([0, 1, 2], array_keys($seen), 'salen las tres cantidades');
    }

    public function test_el_relleno_nunca_se_repite_mas_de_dos_veces_ni_iguala_a_un_premio(): void
    {
        foreach (['none', 'p10k', 'p50k'] as $prize) {
            for ($i = 0; $i < 300; $i++) {
                $faces = $this->build($prize)['gameResult']['faces'];
                $counts = array_count_values(array_filter($faces, fn ($f) => $f !== '8'));
                $this->assertLessThanOrEqual(2, max($counts));
                $this->assertEmpty(array_intersect(array_keys($counts), ['p10k', 'p50k', 'none']));
            }
        }
    }

    public function test_el_gameConfig_trae_lo_que_el_cliente_necesita_y_nada_de_mas(): void
    {
        $this->assertSame(['cards' => 12, 'columns' => 4], $this->build('p10k')['gameConfig']);
        $this->assertSame(['cards' => 9, 'columns' => 3], $this->build('p10k', ['cards' => 9, 'columns' => 3])['gameConfig']);
    }

    public function test_con_un_azar_inyectado_el_resultado_es_reproducible(): void
    {
        $mk = fn () => (new Presentation(fn (int $a, int $b) => $a))->build('bomb', Fixtures::gameConfig('bomb'), $this->defs(), 'p50k');
        $this->assertSame($mk(), $mk());
    }
}
