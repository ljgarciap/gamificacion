<?php

namespace Tests\Feature\Dev;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Con la configuración por defecto, las utilidades de desarrollo NO EXISTEN. */
final class DevEndpointsDisabledTest extends TestCase
{
    public function test_las_rutas_de_desarrollo_no_existen_por_defecto(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();
        $this->assertNotContains('v1/dev/token', $uris);
        $this->assertNotContains('v1/dev/demo-codes', $uris);

        $this->postJson('/v1/dev/token', ['userId' => 'x'])->assertNotFound();
        $this->getJson('/v1/dev/demo-codes')->assertNotFound();
    }
}
