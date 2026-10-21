<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\PasswordPolicy;
use Tests\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private function errors(string $password, string $email = 'ana.lopez@ejemplo.com'): array
    {
        return PasswordPolicy::errors($password, $email);
    }

    public function test_una_contrasena_buena_se_acepta(): void
    {
        $this->assertSame([], $this->errors('Tr3n-Azul-Cafetera-91'));
        $this->assertSame([], $this->errors('una frase larga y distinta de las demás'));
    }

    public function test_exige_el_minimo_de_caracteres_configurado(): void
    {
        $this->assertNotSame([], $this->errors('Corta-1!'));
        config(['gamification.admin.password_min_length' => 8]);
        $this->assertSame([], $this->errors('Tr3n-Azul'));
    }

    public function test_rechaza_las_comunes_aunque_les_pongan_numeros_o_simbolos(): void
    {
        foreach (['password1234567', 'Password2024!!!!', 'QWERTYUIOP12345', '1234567890123', 'supergiros2026', 'Bienvenido#2026'] as $common) {
            $this->assertContains('Es demasiado común.', $this->errors($common), $common);
        }
    }

    public function test_no_puede_contener_el_correo(): void
    {
        $this->assertContains('No debe contener tu correo.', $this->errors('mi-ana.lopez-Segura77'));
        $this->assertSame([], $this->errors('Verde-Montaña-4821', 'a@b.co'), 'una parte local muy corta no se considera');
    }

    public function test_rechaza_poca_variedad_de_caracteres(): void
    {
        $this->assertContains('Usa más variedad de caracteres.', $this->errors('aaaaaaaaaaaaaaaa'));
    }

    public function test_el_maximo_es_en_bytes_porque_bcrypt_ignora_lo_que_sobra(): void
    {
        $this->assertContains('Debe tener como máximo 72 bytes.', $this->errors(str_repeat('Ab1-', 19)));
        $this->assertSame([], $this->errors('Ab1-Cd2-Ef3-Gh4-Ij5-Kl6-Mn7-Op8-Qr9-St0'));
        $this->assertContains('Debe tener como máximo 72 bytes.', $this->errors(str_repeat('ñ', 40).'-Abc9'), 'cuentan bytes, no letras');
    }
}
