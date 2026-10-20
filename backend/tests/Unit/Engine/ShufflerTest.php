<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Drbg;
use App\Engine\Shuffler;
use PHPUnit\Framework\TestCase;

final class ShufflerTest extends TestCase
{
    private static function drbg(string $label): Drbg
    {
        return new Drbg(hash('sha256', $label, true));
    }

    public function test_es_una_permutacion_de_la_entrada(): void
    {
        $items = range(1, 100);
        $out = Shuffler::permute($items, self::drbg('perm'));
        $sorted = $out;
        sort($sorted);
        $this->assertSame($items, $sorted);
        $this->assertNotSame($items, $out, 'con 100 elementos es prácticamente imposible que quede igual');
    }

    public function test_es_determinista(): void
    {
        $items = range(1, 50);
        $this->assertSame(
            Shuffler::permute($items, self::drbg('x')),
            Shuffler::permute($items, self::drbg('x')),
        );
    }

    public function test_semillas_distintas_dan_barajas_distintas(): void
    {
        $items = range(1, 30);
        $this->assertNotSame(
            Shuffler::permute($items, self::drbg('a')),
            Shuffler::permute($items, self::drbg('b')),
        );
    }

    public function test_casos_borde(): void
    {
        $this->assertSame([], Shuffler::permute([], self::drbg('e')));
        $this->assertSame(['solo'], Shuffler::permute(['solo'], self::drbg('e')));
    }

    public function test_no_modifica_el_arreglo_original(): void
    {
        $items = [3, 1, 2];
        Shuffler::permute($items, self::drbg('m'));
        $this->assertSame([3, 1, 2], $items);
    }

    /**
     * CA-23 — control estadístico de uniformidad por posición.
     * Se baraja [A..E] 20.000 veces con 20.000 semillas distintas (derivadas de un contador, no escogidas a mano).
     * Si la baraja es uniforme, cada elemento cae en cada posición con la misma frecuencia (4000 ± ruido).
     * Estadístico chi-cuadrado sobre las 25 celdas, (5-1)^2 = 16 grados de libertad. El umbral (70) se fijó ANTES de
     * ver el resultado: corresponde a p ≈ 1e-8, es decir, un fallo indicaría un sesgo real y no mala suerte.
     */
    public function test_cada_elemento_es_equiprobable_en_cada_posicion(): void
    {
        $k = 5;
        $runs = 20_000;
        $items = ['A', 'B', 'C', 'D', 'E'];
        $table = array_fill(0, $k, array_fill_keys($items, 0));

        for ($i = 0; $i < $runs; $i++) {
            $out = Shuffler::permute($items, new Drbg(hash('sha256', "pos-$i", true)));
            foreach ($out as $pos => $item) {
                $table[$pos][$item]++;
            }
        }

        $expected = $runs / $k;
        $chi2 = 0.0;
        foreach ($table as $row) {
            foreach ($row as $count) {
                $chi2 += (($count - $expected) ** 2) / $expected;
            }
        }
        $this->assertLessThan(70.0, $chi2, 'chi2='.round($chi2, 2));
    }

    /**
     * Las 6 permutaciones de 3 elementos deben aparecer con la misma frecuencia (df = 5, p ≈ 1e-8 ≈ 45; umbral 50).
     * Detecta sesgos que la tabla por posición no ve (dependencias entre posiciones).
     */
    public function test_las_seis_permutaciones_de_tres_elementos_son_equiprobables(): void
    {
        $runs = 60_000;
        $counts = [];
        for ($i = 0; $i < $runs; $i++) {
            $key = implode('', Shuffler::permute(['a', 'b', 'c'], new Drbg(hash('sha256', "perm3-$i", true))));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $this->assertCount(6, $counts, 'deben aparecer las 6 permutaciones');
        $expected = $runs / 6;
        $chi2 = 0.0;
        foreach ($counts as $c) {
            $chi2 += (($c - $expected) ** 2) / $expected;
        }
        $this->assertLessThan(50.0, $chi2, 'chi2='.round($chi2, 2).' '.json_encode($counts));
    }
}
