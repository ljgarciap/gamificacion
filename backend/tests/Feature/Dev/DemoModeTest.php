<?php

namespace Tests\Feature\Dev;

use App\Domain\Auth\HostTokenVerifier;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\DbTestCase;
use Tests\Support\HostTokens;

/** Modo demo (servidor de demostración): funciona en PRODUCCIÓN solo si se activa a propósito, y no reabre las rutas /v1/dev/*. */
final class DemoModeTest extends DbTestCase
{
    private string $storage;

    protected function extraEnv(): array
    {
        return ['GAMIF_DEMO_MODE' => 'true', 'APP_ENV' => 'production', 'GAMIF_TRUSTED_PROXIES' => 'none', 'GAMIF_DEV_ENDPOINTS' => 'true'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['gamification.hosts' => ['standalone-dev' => ['alg' => 'HS256', 'key' => base64_encode(HostTokens::SECRET)]]]);
        config(['gamification.token.audience' => 'gamification']);
        $this->storage = sys_get_temp_dir().'/demo-mode-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_en_produccion_con_el_modo_demo_el_token_funciona_y_el_verificador_real_lo_acepta(): void
    {
        $this->assertTrue($this->app->environment('production'));
        $r = $this->postJson('/v1/demo/token', ['userId' => 'maria']);
        $r->assertOk()->assertJsonPath('userId', 'standalone-dev:maria');
        $this->assertSame('standalone-dev:maria', HostTokenVerifier::fromConfig()->verify($r->json('token'))->id());

        config(['gamification.demo.initial_balance' => 50000]);
        $this->getJson('/v1/balance', ['Authorization' => 'Bearer '.$r->json('token')])->assertOk()->assertJsonPath('amount', 50000);
    }

    public function test_la_lista_de_codigos_responde_y_nunca_trae_datos_de_mas(): void
    {
        $this->getJson('/v1/demo/codes')->assertOk()->assertJsonStructure(['codes'])->assertHeader('Cache-Control');
    }

    public function test_userId_invalido_se_rechaza_tambien_aqui(): void
    {
        $this->postJson('/v1/demo/token', ['userId' => 'con espacio'])->assertStatus(422)->assertJsonPath('error.code', 'invalidUserId');
    }

    public function test_el_modo_demo_no_reabre_las_rutas_de_desarrollo_en_produccion(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();
        $this->assertNotContains('v1/dev/token', $uris);
        $this->assertNotContains('v1/dev/demo-codes', $uris);
        $this->postJson('/v1/dev/token')->assertNotFound();
    }

    public function test_el_limite_de_peticiones_por_ip_frena_el_abuso(): void
    {
        $codes = [];
        for ($i = 0; $i < 32; $i++) {
            $codes[] = $this->postJson('/v1/demo/token')->status();
        }
        $this->assertContains(429, $codes, 'a partir de 30 por minuto se frena');
    }
}
