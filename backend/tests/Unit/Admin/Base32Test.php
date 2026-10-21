<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Base32;
use PHPUnit\Framework\TestCase;

/** Vectores de RFC 4648 §10 (sin el relleno `=`). */
final class Base32Test extends TestCase
{
    public function test_vectores_del_rfc_4648(): void
    {
        $vectores = ['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'];
        foreach ($vectores as $plain => $encoded) {
            $this->assertSame($encoded, Base32::encode((string) $plain), "codificar «{$plain}»");
            $this->assertSame((string) $plain, Base32::decode($encoded), "decodificar «{$encoded}»");
        }
    }

    public function test_ida_y_vuelta_con_bytes_aleatorios(): void
    {
        for ($n = 1; $n <= 40; $n++) {
            $bytes = random_bytes($n);
            $this->assertSame($bytes, Base32::decode(Base32::encode($bytes)));
        }
    }

    public function test_decodificar_tolera_minusculas_espacios_guiones_y_relleno(): void
    {
        $this->assertSame('foobar', Base32::decode('mzxw 6ytb-oi=='));
    }

    public function test_un_caracter_fuera_del_alfabeto_se_rechaza(): void
    {
        foreach (['MZXW1', 'MZXW8', 'MZXW!', 'MZXWé'] as $bad) {
            try {
                Base32::decode($bad);
                $this->fail("debía rechazar «{$bad}»");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
