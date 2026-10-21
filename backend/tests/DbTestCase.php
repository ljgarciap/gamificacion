<?php

namespace Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas que usan la base de datos. Cada prueba corre dentro de una transacción que se revierte.
 */
abstract class DbTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Comprueba que la base de datos RECHAZA la operación. Se envuelve en una transacción (un SAVEPOINT dentro de la de
     * la prueba) porque en PostgreSQL un error aborta la transacción en curso: sin esto, ninguna sentencia posterior
     * funcionaría. $fragment debe aparecer en el mensaje, para no aceptar un rechazo por una razón equivocada.
     */
    protected function assertDbRejects(callable $operation, string $fragment): void
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage(), 'la base rechazó la operación pero por otra razón');

            return;
        }
        $this->fail("La base de datos debía rechazar la operación (esperaba: «{$fragment}»).");
    }
}
