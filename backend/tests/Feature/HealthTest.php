<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_la_api_responde_en_up(): void
    {
        $this->get('/up')->assertOk();
    }

    /**
     * Guarda de seguridad: las pruebas verifican UPDATE atómico y restricciones propias de PostgreSQL,
     * y las que usan RefreshDatabase borran todo. Deben correr SIEMPRE contra PostgreSQL y contra la base
     * de prueba, nunca contra la de desarrollo.
     */
    public function test_las_pruebas_corren_en_postgresql_y_en_la_base_de_prueba(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('gamification_test', DB::connection()->getDatabaseName());
    }

    public function test_postgresql_responde_y_es_la_version_esperada(): void
    {
        $version = DB::selectOne('SHOW server_version')->server_version;
        $this->assertStringStartsWith('16.', $version);
    }
}
