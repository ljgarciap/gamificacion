<?php

namespace Tests\Feature\Dev;

use Tests\TestCase;

/**
 * Las rutas del antiguo modo demo (`/v1/demo/token`, `/v1/demo/codes`, activadas por GAMIF_DEMO_MODE) se retiraron con la muestra real
 * (SPEC-006): ya no existen en ningún entorno y la variable no las revive. Eran un emisor de tokens abierto para CUALQUIER usuario.
 */
final class LegacyDemoRoutesTest extends TestCase
{
    protected function extraEnv(): array
    {
        return ['GAMIF_DEMO_MODE' => 'true', 'APP_ENV' => 'production', 'GAMIF_TRUSTED_PROXIES' => 'none', 'GAMIF_DEV_ENDPOINTS' => 'true'];
    }

    public function test_el_emisor_abierto_antiguo_ya_no_existe_ni_con_la_variable_vieja(): void
    {
        $this->postJson('/v1/demo/token', ['userId' => 'maria'])->assertNotFound();
        $this->getJson('/v1/demo/codes')->assertNotFound();
        // Y las rutas de desarrollo siguen sin existir en producción aunque se pida GAMIF_DEV_ENDPOINTS.
        $this->postJson('/v1/dev/token')->assertNotFound();
        $this->getJson('/v1/dev/demo-codes')->assertNotFound();
    }

    public function test_config_ya_no_tiene_la_llave_del_modo_demo_antiguo(): void
    {
        $this->assertNull(config('gamification.demo.enabled'));
    }
}
