<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Base exclusiva de las pruebas. Nunca la de desarrollo. */
    public const TEST_DATABASE = 'gamification_test';

    /** Secretos FIJOS de prueba (32 bytes). Jamás se usan fuera de los tests. */
    public const TEST_PRIZE_KEY = 'KKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKK';

    public const TEST_PEPPER = 'PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP';

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

        // Los secretos de prueba no dependen del .env del desarrollador (ni de que exista).
        $app['config']->set('gamification.prize_map_key', base64_encode(self::TEST_PRIZE_KEY));
        $app['config']->set('gamification.code_pepper', base64_encode(self::TEST_PEPPER));

        return $app;
    }
}
