<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Base exclusiva de las pruebas. Nunca la de desarrollo. */
    public const TEST_DATABASE = 'gamification_test';

    /**
     * Las pruebas usan RefreshDatabase (borran todo) y verifican comportamiento propio de PostgreSQL.
     * Las variables de entorno que Docker inyecta en el contenedor ganan a las de phpunit.xml (incluso
     * con force="true"), así que la base se fija aquí, al crear la aplicación y antes de que cualquier
     * trait de prueba toque la conexión.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.database', self::TEST_DATABASE);
        $app['db']->purge('pgsql');

        return $app;
    }
}
