<?php

namespace Tests\Feature\Dev;

use Tests\TestCase;

/** Sin GAMIF_DEMO_MODE las rutas del modo demo no existen (ni en local ni en producción). */
final class DemoModeOffTest extends TestCase
{
    public function test_apagado_no_hay_rutas_de_demo(): void
    {
        $this->postJson('/v1/demo/token')->assertNotFound();
        $this->getJson('/v1/demo/codes')->assertNotFound();
    }
}
