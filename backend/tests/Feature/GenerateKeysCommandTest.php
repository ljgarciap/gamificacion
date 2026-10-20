<?php

namespace Tests\Feature;

use App\Domain\Crypto\MissingSecret;
use App\Domain\Crypto\Secrets;
use Tests\TestCase;

final class GenerateKeysCommandTest extends TestCase
{
    private string $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = tempnam(sys_get_temp_dir(), 'env-test-');
        file_put_contents($this->env, '');
    }

    protected function tearDown(): void
    {
        @unlink($this->env);
        parent::tearDown();
    }

    private function value(string $name): ?string
    {
        preg_match('/^'.$name.'=(\S+)$/m', (string) file_get_contents($this->env), $m);

        return $m[1] ?? null;
    }

    public function test_sin_write_solo_imprime_y_no_toca_el_archivo(): void
    {
        $this->artisan('gamification:keys', ['--path' => $this->env])
            ->expectsOutputToContain('GAMIF_PRIZE_MAP_KEY=')
            ->expectsOutputToContain('GAMIF_CODE_PEPPER=')
            ->assertSuccessful();
        $this->assertSame('', (string) file_get_contents($this->env));
    }

    public function test_con_write_agrega_dos_claves_validas_de_32_bytes(): void
    {
        $this->artisan('gamification:keys', ['--write' => true, '--path' => $this->env])->assertSuccessful();

        foreach (['GAMIF_PRIZE_MAP_KEY', 'GAMIF_CODE_PEPPER'] as $name) {
            $decoded = base64_decode((string) $this->value($name), true);
            $this->assertNotFalse($decoded, $name);
            $this->assertSame(32, strlen($decoded), $name);
        }
        $this->assertNotSame($this->value('GAMIF_PRIZE_MAP_KEY'), $this->value('GAMIF_CODE_PEPPER'));
    }

    public function test_nunca_pisa_una_clave_existente(): void
    {
        $this->artisan('gamification:keys', ['--write' => true, '--path' => $this->env])->assertSuccessful();
        $before = file_get_contents($this->env);

        $this->artisan('gamification:keys', ['--write' => true, '--path' => $this->env])
            ->expectsOutputToContain('ya existe')
            ->assertSuccessful();
        $this->assertSame($before, file_get_contents($this->env), 'perder la clave del mapa dejaría ilegibles los lotes ya comprometidos');
    }

    public function test_agrega_solo_la_que_falta_y_respeta_el_resto_del_archivo(): void
    {
        file_put_contents($this->env, "APP_NAME=Prueba\nGAMIF_CODE_PEPPER=".base64_encode(str_repeat('p', 32)));

        $this->artisan('gamification:keys', ['--write' => true, '--path' => $this->env])->assertSuccessful();

        $content = (string) file_get_contents($this->env);
        $this->assertStringContainsString("APP_NAME=Prueba\n", $content);
        $this->assertSame(base64_encode(str_repeat('p', 32)), $this->value('GAMIF_CODE_PEPPER'));
        $this->assertNotNull($this->value('GAMIF_PRIZE_MAP_KEY'), 'agregó la que faltaba, en su propia línea (el archivo no terminaba en salto de línea)');
    }

    public function test_cada_ejecucion_genera_claves_distintas(): void
    {
        $other = tempnam(sys_get_temp_dir(), 'env-test-');
        try {
            $this->artisan('gamification:keys', ['--write' => true, '--path' => $this->env])->assertSuccessful();
            $this->artisan('gamification:keys', ['--write' => true, '--path' => $other])->assertSuccessful();
            $this->assertNotSame($this->value('GAMIF_PRIZE_MAP_KEY'), preg_match('/GAMIF_PRIZE_MAP_KEY=(\S+)/', (string) file_get_contents($other), $m) ? $m[1] : null);
        } finally {
            @unlink($other);
        }
    }

    // --- Validación de los secretos ------------------------------------------------------------------------------

    public function test_un_secreto_ausente_o_invalido_falla_fuerte(): void
    {
        foreach ([null, '', 'no-es-base64!!', base64_encode('corto'), base64_encode(str_repeat('x', 33))] as $bad) {
            config(['gamification.prize_map_key' => $bad]);
            try {
                Secrets::key('prize_map_key');
                $this->fail('debía fallar con '.var_export($bad, true));
            } catch (MissingSecret) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_un_secreto_valido_se_decodifica(): void
    {
        config(['gamification.prize_map_key' => base64_encode(str_repeat('k', 32))]);
        $this->assertSame(str_repeat('k', 32), Secrets::key('prize_map_key'));
    }
}
