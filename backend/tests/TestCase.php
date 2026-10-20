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
     * Entorno de las pruebas, fijado ANTES de que arranque la aplicación.
     *
     * Las variables que Docker inyecta en el contenedor (DB_DATABASE, CACHE_STORE... desde backend/.env) ganan a phpunit.xml, incluso
     * con force="true". Y corregirlo DESPUÉS de arrancar no sirve: el limitador de peticiones y la caché ya habrían guardado una
     * referencia a la conexión original, y al reconectarse (ese objeto viejo le pide al gestor "reconecta pgsql") derribarían la
     * conexión vigente, la que tiene la transacción de la prueba, haciendo desaparecer las filas. Por eso aquí se escribe el entorno
     * en los tres sitios desde los que Laravel lo lee, antes de crear la aplicación, y no se hace `purge` de nada.
     */
    private const TEST_ENV = [
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => self::TEST_DATABASE,
        // Caché, sesiones y cola en memoria: cada prueba parte limpia y sin tocar la base. La caché real (base de datos) se
        // ejercita en las pruebas de concurrencia.
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ];

    public function createApplication(): Application
    {
        foreach (self::TEST_ENV as $name => $value) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $app = parent::createApplication();

        // Los secretos de prueba no dependen del .env del desarrollador (ni de que exista).
        $app['config']->set('gamification.prize_map_key', base64_encode(self::TEST_PRIZE_KEY));
        $app['config']->set('gamification.code_pepper', base64_encode(self::TEST_PEPPER));

        return $app;
    }
}
