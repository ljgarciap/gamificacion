<?php

namespace Tests\Unit\Campaigns;

use App\Domain\Campaigns\ExtraSpins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtraSpinsTest extends TestCase
{
    public function test_un_premio_de_giro_extra_concede_uno_por_omision_o_lo_declarado(): void
    {
        $this->assertSame(1, ExtraSpins::fromMeta('extraSpin', []));
        $this->assertSame(1, ExtraSpins::fromMeta('extraSpin', null));
        $this->assertSame(3, ExtraSpins::fromMeta('extraSpin', ['extra_spins' => 3]));
        $this->assertSame(ExtraSpins::MAX, ExtraSpins::fromMeta('extraSpin', ['extra_spins' => ExtraSpins::MAX]));
    }

    public function test_otros_tipos_no_conceden_giros(): void
    {
        foreach (['money', 'coupon', 'points', 'none'] as $type) {
            $this->assertSame(0, ExtraSpins::fromMeta($type, ['coupon_code' => 'X']));
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidValues(): array
    {
        return [
            'cero' => [0], 'negativo' => [-40], 'sobre el tope' => [ExtraSpins::MAX + 1], 'texto' => ['3'], 'decimal' => [1.5],
            'booleano' => [true], 'nulo' => [null], 'desbordaría un entero de 32 bits' => [4_000_000_000], 'lista' => [[1]],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_un_valor_invalido_se_rechaza_y_el_modo_tolerante_no_concede_giros(mixed $value): void
    {
        $this->assertSame(0, ExtraSpins::lenient('extraSpin', ['extra_spins' => $value]));
        $this->expectException(\InvalidArgumentException::class);
        ExtraSpins::fromMeta('extraSpin', ['extra_spins' => $value]);
    }

    public function test_extra_spins_en_otro_tipo_de_premio_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExtraSpins::fromMeta('money', ['extra_spins' => 2]);
    }
}
