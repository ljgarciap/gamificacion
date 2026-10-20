<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Pool;
use App\Engine\PrizeAssigner;
use PHPUnit\Framework\TestCase;

final class PoolAndAssignerTest extends TestCase
{
    private const SEED_A = 'semilla-a';

    private static function seed(string $label): string
    {
        return hash('sha256', $label, true);
    }

    /** @return list<array{prize_id: string, quantity: int}> */
    private static function entries(array $map): array
    {
        $out = [];
        foreach ($map as $id => $qty) {
            $out[] = ['prize_id' => (string) $id, 'quantity' => $qty];
        }

        return $out;
    }

    // --- Validación del pool -------------------------------------------------------------------------------

    public function test_pool_invalido_se_rechaza(): void
    {
        $malos = [
            'vacío' => [],
            'todo en cero' => self::entries(['a' => 0]),
            'cantidad negativa' => self::entries(['a' => -1, 'b' => 5]),
            'id vacío' => [['prize_id' => '', 'quantity' => 1]],
            'id de más de 64 bytes' => [['prize_id' => str_repeat('x', 65), 'quantity' => 1]],
            'id no UTF-8' => [['prize_id' => "\xFF\xFE", 'quantity' => 1]],
            'cantidad como texto' => [['prize_id' => 'a', 'quantity' => '5']],
            'cantidad decimal' => [['prize_id' => 'a', 'quantity' => 1.5]],
            'sin cantidad' => [['prize_id' => 'a']],
            'id como número' => [['prize_id' => 5, 'quantity' => 1]],
            'premio repetido' => [['prize_id' => 'a', 'quantity' => 1], ['prize_id' => 'a', 'quantity' => 2]],
        ];
        foreach ($malos as $nombre => $entries) {
            try {
                Pool::fromEntries($entries);
                $this->fail("debía rechazar: $nombre");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_pool_no_puede_superar_el_maximo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pool::fromEntries(self::entries(['a' => 11]), maxTotal: 10);
    }

    public function test_ids_numericos_se_tratan_como_texto(): void
    {
        // PHP convierte las claves numéricas de un arreglo en enteros: no debe afectar al id ni al orden.
        $pool = Pool::fromEntries(self::entries(['10' => 1, '9' => 1, '1' => 1]));
        $this->assertSame(['1', '10', '9'], array_column($pool->entries(), 0), 'orden por bytes: "1" < "10" < "9"');
    }

    public function test_hash_no_depende_del_orden_de_entrada_pero_si_de_los_datos(): void
    {
        $a = Pool::fromEntries(self::entries(['a' => 1, 'b' => 2]));
        $b = Pool::fromEntries(self::entries(['b' => 2, 'a' => 1]));
        $c = Pool::fromEntries(self::entries(['a' => 2, 'b' => 1]));
        $this->assertSame($a->hash(), $b->hash());
        $this->assertNotSame($a->hash(), $c->hash());
    }

    public function test_un_premio_con_cantidad_cero_cambia_el_hash_y_no_aparece_en_la_expansion(): void
    {
        $con = Pool::fromEntries(self::entries(['a' => 2, 'inactivo' => 0]));
        $sin = Pool::fromEntries(self::entries(['a' => 2]));
        $this->assertNotSame($con->hash(), $sin->hash(), 'el pool comprometido debe reflejar exactamente lo definido');
        $this->assertSame(['a', 'a'], $con->expanded());
    }

    // --- Asignación ----------------------------------------------------------------------------------------

    public function test_ca20_los_conteos_asignados_son_exactamente_el_stock_en_pools_aleatorios(): void
    {
        mt_srand(20251020); // solo para generar casos de prueba; el motor no usa funciones de azar
        for ($case = 0; $case < 300; $case++) {
            $map = [];
            $types = mt_rand(1, 9);
            for ($i = 0; $i < $types; $i++) {
                $map["p$i"] = mt_rand(0, 40);
            }
            if (array_sum($map) === 0) {
                continue;
            }
            $pool = Pool::fromEntries(self::entries($map));
            $out = PrizeAssigner::assign($pool, self::seed("s$case"), self::seed("b$case"), "lote-$case");

            $this->assertCount($pool->total(), $out, "caso $case");
            $expected = array_filter($map, static fn (int $q): bool => $q > 0);
            $actual = array_count_values($out);
            ksort($expected);
            ksort($actual);
            $this->assertEquals($expected, $actual, "caso $case: los conteos deben ser exactamente el stock");
        }
    }

    public function test_ca21_misma_entrada_misma_asignacion_y_cualquier_cambio_la_altera(): void
    {
        $pool = Pool::fromEntries(self::entries(['none' => 60, 'p10k' => 30, 'p50k' => 9, 'p100k' => 1]));
        $base = PrizeAssigner::assign($pool, self::seed('s'), self::seed('b'), 'lote');

        $this->assertSame($base, PrizeAssigner::assign($pool, self::seed('s'), self::seed('b'), 'lote'));
        $this->assertNotSame($base, PrizeAssigner::assign($pool, self::seed('otra'), self::seed('b'), 'lote'), 'otra semilla');
        $this->assertNotSame($base, PrizeAssigner::assign($pool, self::seed('s'), self::seed('otra'), 'lote'), 'otra baliza');
        $this->assertNotSame($base, PrizeAssigner::assign($pool, self::seed('s'), self::seed('b'), 'otro-lote'), 'otro lote (R8)');
    }

    public function test_un_pool_de_un_solo_codigo(): void
    {
        $pool = Pool::fromEntries(self::entries(['unico' => 1]));
        $this->assertSame(['unico'], PrizeAssigner::assign($pool, self::seed('s'), self::seed('b'), 'l'));
    }

    public function test_el_premio_mayor_cae_en_posiciones_variadas_entre_lotes(): void
    {
        // Con 100 códigos y 1 premio mayor, a lo largo de 2000 lotes el premio debe visitar casi todas las posiciones
        // (esperado ≈ 20 por posición). No es una prueba formal (eso es el chi-cuadrado de ShufflerTest): es una alarma
        // de humo para un motor que "siempre" lo pone en el mismo sitio.
        $pool = Pool::fromEntries(self::entries(['none' => 99, 'mayor' => 1]));
        $posiciones = [];
        for ($i = 0; $i < 2000; $i++) {
            $out = PrizeAssigner::assign($pool, self::seed("s$i"), self::seed('b'), 'l');
            $posiciones[array_search('mayor', $out, true)] = true;
        }
        $this->assertGreaterThan(95, count($posiciones), 'el premio mayor debe visitar casi todas las 100 posiciones');
    }

    public function test_rendimiento_de_un_lote_de_100_mil_codigos(): void
    {
        $pool = Pool::fromEntries(self::entries(['none' => 99_000, 'p10k' => 900, 'p100k' => 100]));
        $t = microtime(true);
        $out = PrizeAssigner::assign($pool, self::seed('s'), self::seed('b'), 'grande');
        $segundos = microtime(true) - $t;

        $this->assertCount(100_000, $out);
        $this->assertLessThan(10.0, $segundos, 'asignar 100 mil códigos debe tardar segundos, no minutos');
    }
}
