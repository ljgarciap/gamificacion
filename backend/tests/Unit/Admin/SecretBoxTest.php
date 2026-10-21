<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    private function box(string $keyChar = 'k'): SecretBox
    {
        return new SecretBox(str_repeat($keyChar, 32));
    }

    public function test_cifra_y_descifra(): void
    {
        $sealed = $this->box()->seal('secreto-totp', 'admin_users|1|totp_secret_enc');
        $this->assertSame('secreto-totp', $this->box()->open($sealed, 'admin_users|1|totp_secret_enc'));
        $this->assertStringNotContainsString('secreto-totp', $sealed);
    }

    public function test_cada_cifrado_es_distinto_aunque_el_texto_sea_igual(): void
    {
        $this->assertNotSame($this->box()->seal('x', 'c'), $this->box()->seal('x', 'c'));
    }

    public function test_copiado_a_otra_fila_o_columna_no_abre(): void
    {
        $sealed = $this->box()->seal('secreto', 'admin_users|1|totp_secret_enc');
        foreach (['admin_users|2|totp_secret_enc', 'admin_users|1|otra_columna', 'hosts|1|totp_secret_enc'] as $otro) {
            $this->expectedFailure(fn () => $this->box()->open($sealed, $otro), $otro);
        }
    }

    public function test_con_otra_clave_o_alterado_no_abre(): void
    {
        $sealed = $this->box()->seal('secreto', 'c');
        $this->expectedFailure(fn () => $this->box('z')->open($sealed, 'c'), 'otra clave');
        $tampered = $sealed;
        $tampered[strlen($tampered) - 1] = $tampered[strlen($tampered) - 1] ^ "\x01";
        $this->expectedFailure(fn () => $this->box()->open($tampered, 'c'), 'alterado');
        $this->expectedFailure(fn () => $this->box()->open('corto', 'c'), 'corto');
    }

    private function expectedFailure(callable $f, string $label): void
    {
        try {
            $f();
            $this->fail("debía fallar: $label");
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
