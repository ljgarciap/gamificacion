<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Commitment;
use App\Engine\Domain;
use App\Engine\Pool;
use App\Engine\PrizeAssigner;
use PHPUnit\Framework\TestCase;

/** Reserva del giro extra (SPEC-003 P6a): el motor puro. Los vectores cruzados con Python están en VectorsTest. */
final class ReserveEngineTest extends TestCase
{
    private const SEED = '12345678901234567890123456789012';

    private const BEACON = 'abcdefghijklmnopqrstuvwxyz012345';

    private function pool(): Pool
    {
        return Pool::fromEntries([
            ['prize_id' => 'none', 'quantity' => 6], ['prize_id' => 'p10k', 'quantity' => 3],
            ['prize_id' => 'extra', 'quantity' => 2], ['prize_id' => 'p50k', 'quantity' => 1],
        ]);
    }

    public function test_con_reserva_cero_el_mazo_es_exactamente_el_v1(): void
    {
        $deck = PrizeAssigner::assignDeck($this->pool(), 0, self::SEED, self::BEACON, 'b1');
        $this->assertSame(PrizeAssigner::assign($this->pool(), self::SEED, self::BEACON, 'b1'), $deck['codes']);
        $this->assertSame([], $deck['reserve']);
    }

    public function test_el_mazo_se_parte_en_n_codigos_y_r_casillas_y_conserva_el_stock(): void
    {
        $deck = PrizeAssigner::assignDeck($this->pool(), 3, self::SEED, self::BEACON, 'b1');
        $this->assertCount(9, $deck['codes']);
        $this->assertCount(3, $deck['reserve']);
        $all = array_merge($deck['codes'], $deck['reserve']);
        $counts = array_count_values($all);
        ksort($counts);
        $this->assertSame(['extra' => 2, 'none' => 6, 'p10k' => 3, 'p50k' => 1], $counts);
    }

    public function test_el_mazo_con_reserva_usa_su_propio_dominio_no_el_v1(): void
    {
        $v1 = PrizeAssigner::assign($this->pool(), self::SEED, self::BEACON, 'b1');
        $deck = PrizeAssigner::assignDeck($this->pool(), 3, self::SEED, self::BEACON, 'b1');
        $this->assertNotSame($v1, array_merge($deck['codes'], $deck['reserve']));
        $this->assertNotSame(Commitment::mixSeed(self::SEED, self::BEACON, 'b1'), Commitment::mixSeedV2(self::SEED, self::BEACON, 'b1'));
        $this->assertNotSame(Domain::COMMIT, Domain::COMMIT_V2);
    }

    public function test_dos_reservas_distintas_dan_compromisos_distintos(): void
    {
        $d = str_repeat('d', 32);
        $a = Commitment::commitV2('b1', $this->pool(), 3, $d, 'chain', 7, self::SEED);
        $b = Commitment::commitV2('b1', $this->pool(), 2, $d, 'chain', 7, self::SEED);
        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, Commitment::commit('b1', $this->pool(), $d, 'chain', 7, self::SEED));
    }

    public function test_version_canonica(): void
    {
        $this->assertSame(1, Commitment::versionFor(0));
        $this->assertSame(2, Commitment::versionFor(1));
        $this->assertSame(2, Commitment::versionFor(500));
        $this->expectException(\InvalidArgumentException::class);
        Commitment::versionFor(-1);
    }

    public function test_reservas_invalidas_se_rechazan(): void
    {
        foreach ([-1, 12, 13] as $bad) {
            try {
                PrizeAssigner::assignDeck($this->pool(), $bad, self::SEED, self::BEACON, 'b1');
                $this->fail("la reserva {$bad} debió rechazarse");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Commitment::commitV2('b1', $this->pool(), 0, str_repeat('d', 32), 'chain', 7, self::SEED);
    }
}
