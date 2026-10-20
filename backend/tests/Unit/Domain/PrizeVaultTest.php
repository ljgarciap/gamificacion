<?php

namespace Tests\Unit\Domain;

use App\Domain\Crypto\CodeHasher;
use App\Domain\Crypto\PrizeVault;
use PHPUnit\Framework\TestCase;

final class PrizeVaultTest extends TestCase
{
    private const KEY = 'KKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKK';

    private function vault(string $key = self::KEY): PrizeVault
    {
        return new PrizeVault($key);
    }

    private function fails(callable $f): bool
    {
        try {
            $f();
        } catch (\RuntimeException) {
            return true;
        }

        return false;
    }

    public function test_ida_y_vuelta_del_premio(): void
    {
        $sealed = $this->vault()->encryptPrize('lote-1', 7, 'p50k');
        $this->assertSame('p50k', $this->vault()->decryptPrize('lote-1', 7, $sealed));
    }

    public function test_el_texto_cifrado_no_revela_el_premio_ni_su_largo_exacto_por_relleno_de_formato(): void
    {
        $sealed = $this->vault()->encryptPrize('lote-1', 7, 'p50k');
        $this->assertStringNotContainsString('p50k', $sealed);
        // nonce (24) + premio (4) + etiqueta de autenticación (16)
        $this->assertSame(24 + 4 + 16, strlen($sealed));
    }

    public function test_cada_cifrado_usa_un_nonce_distinto(): void
    {
        $a = $this->vault()->encryptPrize('lote-1', 7, 'none');
        $b = $this->vault()->encryptPrize('lote-1', 7, 'none');
        $this->assertNotSame($a, $b, 'dos códigos con el mismo premio no deben producir el mismo texto cifrado');
    }

    public function test_el_cifrado_esta_ligado_al_lote_y_al_indice(): void
    {
        $sealed = $this->vault()->encryptPrize('lote-1', 7, 'p50k');
        $this->assertTrue($this->fails(fn () => $this->vault()->decryptPrize('lote-1', 8, $sealed)), 'otro índice');
        $this->assertTrue($this->fails(fn () => $this->vault()->decryptPrize('lote-2', 7, $sealed)), 'otro lote');
    }

    public function test_una_clave_distinta_no_descifra(): void
    {
        $sealed = $this->vault()->encryptPrize('lote-1', 7, 'p50k');
        $this->assertTrue($this->fails(fn () => $this->vault(str_repeat('X', 32))->decryptPrize('lote-1', 7, $sealed)));
    }

    public function test_cualquier_bit_alterado_se_detecta(): void
    {
        $sealed = $this->vault()->encryptPrize('lote-1', 7, 'p50k');
        for ($i = 0; $i < strlen($sealed); $i++) {
            $tampered = $sealed;
            $tampered[$i] = chr(ord($tampered[$i]) ^ 0x01);
            $this->assertTrue($this->fails(fn () => $this->vault()->decryptPrize('lote-1', 7, $tampered)), "byte $i");
        }
    }

    public function test_un_texto_demasiado_corto_se_rechaza(): void
    {
        $this->assertTrue($this->fails(fn () => $this->vault()->decryptPrize('l', 0, 'corto')));
        $this->assertTrue($this->fails(fn () => $this->vault()->decryptPrize('l', 0, '')));
    }

    public function test_la_semilla_ida_y_vuelta_y_ligada_al_lote(): void
    {
        $seed = random_bytes(32);
        $sealed = $this->vault()->encryptSeed('lote-1', $seed);
        $this->assertSame($seed, $this->vault()->decryptSeed('lote-1', $sealed));
        $this->assertTrue($this->fails(fn () => $this->vault()->decryptSeed('lote-2', $sealed)));
    }

    public function test_un_cifrado_de_premio_no_vale_como_semilla_ni_al_reves(): void
    {
        // Las etiquetas de contexto (AAD) separan los usos: no se puede hacer pasar un texto por el otro.
        $prize = $this->vault()->encryptPrize('lote-1', 0, str_repeat('s', 32));
        $this->assertTrue($this->fails(fn () => $this->vault()->decryptSeed('lote-1', $prize)));
    }

    public function test_la_semilla_debe_tener_32_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vault()->encryptSeed('lote-1', 'corta');
    }

    // --- Huella de los códigos -----------------------------------------------------------------------------------

    public function test_la_huella_es_determinista_de_32_bytes_y_depende_del_pepper_y_del_codigo(): void
    {
        $h = new CodeHasher(str_repeat('P', 32));
        $this->assertSame($h->hash('ABC'), $h->hash('ABC'));
        $this->assertSame(32, strlen($h->hash('ABC')));
        $this->assertNotSame($h->hash('ABC'), $h->hash('ABD'));
        $this->assertNotSame($h->hash('ABC'), (new CodeHasher(str_repeat('Q', 32)))->hash('ABC'), 'sin el pepper no se pueden probar códigos');
    }

    public function test_la_huella_no_contiene_el_codigo(): void
    {
        $h = (new CodeHasher(str_repeat('P', 32)))->hash('SECRETO-1234');
        $this->assertStringNotContainsString('SECRETO', $h);
        $this->assertStringNotContainsString('SECRETO', bin2hex($h));
    }
}
