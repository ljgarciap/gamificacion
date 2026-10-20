<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Commitment;
use App\Engine\Pool;
use PHPUnit\Framework\TestCase;

final class CommitmentTest extends TestCase
{
    private static function h(string $label): string
    {
        return hash('sha256', $label, true);
    }

    private static function pool(int $none = 5): Pool
    {
        return Pool::fromEntries([['prize_id' => 'none', 'quantity' => $none], ['prize_id' => 'p', 'quantity' => 1]]);
    }

    private static function base(): string
    {
        return Commitment::commit('lote', self::pool(), self::h('d'), self::h('cadena'), 100, self::h('semilla'));
    }

    public function test_es_determinista_y_de_32_bytes(): void
    {
        $this->assertSame(self::base(), self::base());
        $this->assertSame(32, strlen(self::base()));
    }

    /** Cada campo está ligado al compromiso: cambiar cualquiera de ellos cambia el resultado. */
    public function test_cualquier_campo_cambiado_altera_el_compromiso(): void
    {
        $variantes = [
            'lote' => Commitment::commit('otro', self::pool(), self::h('d'), self::h('cadena'), 100, self::h('semilla')),
            'pool' => Commitment::commit('lote', self::pool(6), self::h('d'), self::h('cadena'), 100, self::h('semilla')),
            'huellas' => Commitment::commit('lote', self::pool(), self::h('otro'), self::h('cadena'), 100, self::h('semilla')),
            'cadena' => Commitment::commit('lote', self::pool(), self::h('d'), self::h('otra'), 100, self::h('semilla')),
            'ronda' => Commitment::commit('lote', self::pool(), self::h('d'), self::h('cadena'), 101, self::h('semilla')),
            'semilla' => Commitment::commit('lote', self::pool(), self::h('d'), self::h('cadena'), 100, self::h('otra')),
        ];
        foreach ($variantes as $campo => $valor) {
            $this->assertNotSame(self::base(), $valor, "cambiar $campo debe cambiar el compromiso");
        }
    }

    public function test_los_campos_de_largo_variable_no_se_pueden_confundir(): void
    {
        // Sin prefijo de largo, ("ab", "c") y ("a", "bc") concatenarían igual. Con él, son distintos.
        $a = Commitment::commit('ab', self::pool(), self::h('d'), 'c', 1, self::h('s'));
        $b = Commitment::commit('a', self::pool(), self::h('d'), 'bc', 1, self::h('s'));
        $this->assertNotSame($a, $b);
    }

    public function test_tamanos_invalidos(): void
    {
        $casos = [
            fn () => Commitment::commit('l', self::pool(), 'corto', self::h('c'), 1, self::h('s')),
            fn () => Commitment::commit('l', self::pool(), self::h('d'), self::h('c'), 1, 'corta'),
            fn () => Commitment::commit('l', self::pool(), self::h('d'), self::h('c'), -1, self::h('s')),
            fn () => Commitment::mixSeed('corta', self::h('b'), 'l'),
            fn () => Commitment::codesDigest(['no-son-32-bytes']),
        ];
        foreach ($casos as $i => $caso) {
            try {
                $caso();
                $this->fail("caso $i debía fallar");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_mezcla_de_semilla_depende_de_semilla_baliza_y_lote(): void
    {
        $base = Commitment::mixSeed(self::h('s'), self::h('b'), 'l');
        $this->assertSame(32, strlen($base));
        $this->assertNotSame($base, Commitment::mixSeed(self::h('otra'), self::h('b'), 'l'));
        $this->assertNotSame($base, Commitment::mixSeed(self::h('s'), self::h('otra'), 'l'));
        $this->assertNotSame($base, Commitment::mixSeed(self::h('s'), self::h('b'), 'otro'));
    }

    public function test_el_resumen_de_huellas_depende_del_orden_y_de_la_cantidad(): void
    {
        $a = self::h('1');
        $b = self::h('2');
        $this->assertNotSame(Commitment::codesDigest([$a, $b]), Commitment::codesDigest([$b, $a]));
        $this->assertNotSame(Commitment::codesDigest([$a, $b]), Commitment::codesDigest([$a, $b, $a]));
    }
}
