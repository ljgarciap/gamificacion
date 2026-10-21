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
            ->expectsOutputToContain('GAMIF_ADMIN_KEY=')
            ->assertSuccessful();
        $this->assertSame('', (string) file_get_contents($this->env));
    }

    public function test_con_write_agrega_tres_claves_validas_de_32_bytes(): void
    {
        $this->artisan('gamification:keys', ['--write' => true, '--path' => $this->env])->assertSuccessful();

        foreach (['GAMIF_PRIZE_MAP_KEY', 'GAMIF_CODE_PEPPER', 'GAMIF_ADMIN_KEY'] as $name) {
            $decoded = base64_decode((string) $this->value($name), true);
            $this->assertNotFalse($decoded, $name);
            $this->assertSame(32, strlen($decoded), $name);
        }
        $this->assertCount(3, array_unique([$this->value('GAMIF_PRIZE_MAP_KEY'), $this->value('GAMIF_CODE_PEPPER'), $this->value('GAMIF_ADMIN_KEY')]));
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

    // --- Configuración de desarrollo (--dev) ---------------------------------------------------------------------

    public function test_dev_agrega_el_emisor_de_tokens_con_su_propia_clave_aleatoria(): void
    {
        $this->artisan('gamification:keys', ['--write' => true, '--dev' => true, '--path' => $this->env])->assertSuccessful();

        $raw = (string) $this->value('GAMIF_HOSTS');
        $this->assertStringStartsWith("'", $raw, 'entre comillas simples: es JSON');
        $hosts = json_decode(trim($raw, "'"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['standalone-dev'], array_keys($hosts));
        $this->assertSame('HS256', $hosts['standalone-dev']['alg']);
        $this->assertSame(32, strlen((string) base64_decode($hosts['standalone-dev']['key'], true)));
        $this->assertNotSame($this->value('GAMIF_PRIZE_MAP_KEY'), $hosts['standalone-dev']['key'], 'una clave distinta de la del mapa de premios');
    }

    public function test_dev_activa_lo_necesario_para_trabajar_en_local(): void
    {
        $this->artisan('gamification:keys', ['--write' => true, '--dev' => true, '--path' => $this->env])->assertSuccessful();

        $this->assertSame('true', $this->value('GAMIF_DEV_ENDPOINTS'));
        $this->assertSame('local', $this->value('GAMIF_BEACON'));
        $this->assertSame('http://localhost:8080,http://localhost:8081', $this->value('GAMIF_CORS_ORIGINS'));
        $this->assertSame('120000', $this->value('GAMIF_DEMO_INITIAL_BALANCE'));
    }

    public function test_sin_dev_no_se_agrega_nada_de_desarrollo(): void
    {
        $this->artisan('gamification:keys', ['--write' => true, '--path' => $this->env])->assertSuccessful();
        foreach (['GAMIF_HOSTS', 'GAMIF_DEV_ENDPOINTS', 'GAMIF_BEACON', 'GAMIF_CORS_ORIGINS'] as $name) {
            $this->assertNull($this->value($name), "$name no debe agregarse sin --dev");
        }
    }

    public function test_dev_no_pisa_lo_que_ya_existe_pero_si_agrega_lo_que_falta(): void
    {
        file_put_contents($this->env, "GAMIF_BEACON=drand
GAMIF_DEV_ENDPOINTS=false
");
        $this->artisan('gamification:keys', ['--write' => true, '--dev' => true, '--path' => $this->env])->assertSuccessful();

        $this->assertSame('drand', $this->value('GAMIF_BEACON'), 'respeta lo que ya estaba');
        $this->assertSame('false', $this->value('GAMIF_DEV_ENDPOINTS'));
        $this->assertNotNull($this->value('GAMIF_HOSTS'), 'pero agrega lo que faltaba');
    }

    public function test_dev_sin_write_solo_imprime(): void
    {
        $this->artisan('gamification:keys', ['--dev' => true, '--path' => $this->env])
            ->expectsOutputToContain('GAMIF_HOSTS=')
            ->expectsOutputToContain('GAMIF_BEACON=local')
            ->assertSuccessful();
        $this->assertSame('', (string) file_get_contents($this->env));
    }

    public function test_cada_ejecucion_dev_genera_un_emisor_con_clave_distinta(): void
    {
        $other = tempnam(sys_get_temp_dir(), 'env-test-');
        try {
            $this->artisan('gamification:keys', ['--write' => true, '--dev' => true, '--path' => $this->env])->assertSuccessful();
            $this->artisan('gamification:keys', ['--write' => true, '--dev' => true, '--path' => $other])->assertSuccessful();
            preg_match('/^GAMIF_HOSTS=(.+)$/m', (string) file_get_contents($other), $m);
            $this->assertNotSame($this->value('GAMIF_HOSTS'), $m[1]);
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
