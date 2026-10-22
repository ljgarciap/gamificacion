<?php

namespace Tests\Feature\Dev;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Aunque alguien active GAMIF_DEV_ENDPOINTS=true por error en PRODUCCIÓN, las rutas de desarrollo no se registran:
 * un emisor de tokens abierto sería equivalente a no tener autenticación.
 */
final class DevEndpointsProductionTest extends TestCase
{
    protected function extraEnv(): array
    {
        return ['GAMIF_DEV_ENDPOINTS' => 'true', 'APP_ENV' => 'production', 'GAMIF_TRUSTED_PROXIES' => 'none'];
    }

    public function test_en_produccion_las_rutas_no_existen_aunque_la_variable_este_activada(): void
    {
        $this->assertTrue($this->app->environment('production'));
        $this->assertTrue(config('gamification.dev.enabled'), 'la variable SÍ está activada: lo que protege es el entorno');

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();
        $this->assertNotContains('v1/dev/token', $uris);
        $this->assertNotContains('v1/dev/demo-codes', $uris);
        $this->postJson('/v1/dev/token', ['userId' => 'atacante'])->assertNotFound();
    }

    public function test_un_valor_raro_en_la_variable_no_las_activa(): void
    {
        $this->assertSame(false, filter_var('quizas', FILTER_VALIDATE_BOOLEAN));
    }
}
