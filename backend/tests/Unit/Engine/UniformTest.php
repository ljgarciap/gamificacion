<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Drbg;
use App\Engine\Uniform;
use PHPUnit\Framework\TestCase;

final class UniformTest extends TestCase
{
    /**
     * CA-22 — prueba EXACTA (no estadística) de que no hay sesgo de módulo.
     * Con un rango de 256 valores (un byte) se enumeran las 256 entradas y se cuenta cuántas producen cada resultado:
     * todos los resultados posibles deben salir exactamente del mismo número de entradas, para cada n.
     * La función probada es la misma que usa el motor con rango 2^32.
     */
    public function test_cada_resultado_sale_de_exactamente_el_mismo_numero_de_entradas(): void
    {
        for ($n = 2; $n < 256; $n++) {
            $counts = array_fill(0, $n, 0);
            $accepted = 0;
            for ($word = 0; $word < 256; $word++) {
                $r = Uniform::accept($word, $n, 256);
                if ($r !== null) {
                    $counts[$r]++;
                    $accepted++;
                }
            }
            $each = intdiv(256, $n);
            $this->assertSame(array_fill(0, $n, $each), $counts, "n=$n: algún resultado es más probable que otro");
            $this->assertSame($each * $n, $accepted, "n=$n");
        }
    }

    public function test_el_sesgo_ingenuo_de_modulo_si_existiria_y_aqui_se_evita(): void
    {
        // Documenta POR QUÉ importa: con `x % n` directo y n = 3 sobre 256 valores, el resultado 0 sale 86 veces y los
        // otros 85. La regla de aceptación descarta el sobrante (la entrada 255) y deja 85 / 85 / 85.
        $naive = array_fill(0, 3, 0);
        for ($x = 0; $x < 256; $x++) {
            $naive[$x % 3]++;
        }
        $this->assertSame([86, 85, 85], $naive);
        $this->assertNull(Uniform::accept(255, 3, 256));
    }

    public function test_n_igual_al_rango_o_potencia_de_dos_no_descarta_nada(): void
    {
        foreach ([2, 4, 16, 128, 256] as $n) {
            for ($word = 0; $word < 256; $word++) {
                $this->assertNotNull(Uniform::accept($word, $n, 256), "n=$n word=$word");
            }
        }
    }

    public function test_n_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Uniform::accept(0, 0, 256);
    }

    public function test_n_mayor_que_el_rango_es_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Uniform::accept(0, 257, 256);
    }

    public function test_drbg_uniforme_rechaza_rangos_invalidos(): void
    {
        $d = new Drbg(str_repeat("\x01", 32));
        foreach ([0, -5, Drbg::WORD_RANGE + 1] as $bad) {
            try {
                $d->uniform($bad);
                $this->fail("n=$bad debía rechazarse");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_n_uno_no_consume_bytes(): void
    {
        $a = new Drbg(str_repeat("\x07", 32));
        $b = new Drbg(str_repeat("\x07", 32));
        $a->uniform(1);
        $this->assertSame($b->nextBytes(8), $a->nextBytes(8));
    }

    public function test_la_clave_del_drbg_debe_tener_32_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Drbg('corta');
    }

    /**
     * Control estadístico adicional (la prueba exacta de arriba es la que descarta el sesgo; esta detectaría
     * un error grosero, p. ej. un generador que favorezca valores). Claves fijas => resultado reproducible.
     * Umbral fijado de antemano: chi-cuadrado con 6 grados de libertad, p ≈ 1e-6 ≈ 34; se usa 40.
     */
    public function test_distribucion_uniforme_de_un_dado_de_7_caras(): void
    {
        $n = 7;
        $draws = 70_000;
        $counts = array_fill(0, $n, 0);
        $d = new Drbg(hash('sha256', 'estadistico-uniforme', true));
        for ($i = 0; $i < $draws; $i++) {
            $counts[$d->uniform($n)]++;
        }
        $expected = $draws / $n;
        $chi2 = 0.0;
        foreach ($counts as $c) {
            $chi2 += (($c - $expected) ** 2) / $expected;
        }
        $this->assertLessThan(40.0, $chi2, 'chi2='.round($chi2, 2).' conteos='.implode(',', $counts));
    }
}
