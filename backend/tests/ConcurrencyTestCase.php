<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Parallel;

/**
 * Base de las pruebas de CONCURRENCIA REAL.
 *
 * No usa RefreshDatabase: la transacción envolvente de las otras pruebas haría que ningún otro proceso viera sus datos, y PostgreSQL nunca
 * vería dos transacciones compitiendo. Aquí, en cambio:
 *  - se crea una base de datos TEMPORAL por prueba (nunca toca la de desarrollo ni la de pruebas normales) y se migra;
 *  - los datos se CONFIRMAN de verdad (commit);
 *  - se lanzan procesos reales con conexiones propias (ver Parallel);
 *  - al terminar, se elimina la base temporal.
 *
 * Límite práctico: PostgreSQL admite 100 conexiones por defecto; no se lanzan más de ~50 procesos a la vez.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    private string $tempDatabase = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDatabase = 'gamification_conc_'.bin2hex(random_bytes(5));
        $this->maintenance()->exec('CREATE DATABASE '.$this->tempDatabase);

        // Una conexión NUEVA (no se purga la existente) apuntando a la base temporal, y se vuelve la predeterminada.
        $base = config('database.connections.pgsql');
        config(['database.connections.conc' => ['database' => $this->tempDatabase] + $base]);
        config(['database.default' => 'conc']);

        Artisan::call('migrate', ['--force' => true, '--database' => 'conc']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('conc');
        if ($this->tempDatabase !== '') {
            try {
                $this->maintenance()->exec('DROP DATABASE IF EXISTS '.$this->tempDatabase.' WITH (FORCE)');
            } catch (\Throwable) {
                // Si no se pudo borrar, no debe ocultar el resultado de la prueba.
            }
        }
        parent::tearDown();
    }

    /** Conexión a la base de mantenimiento "postgres", para crear y borrar la temporal. */
    private function maintenance(): \PDO
    {
        $c = config('database.connections.pgsql');

        return new \PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname=postgres",
            $c['username'], $c['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * Lanza $workers procesos a la vez; cada uno ejecuta $attempt($i) y devuelve un arreglo serializable.
     * Cualquier excepción inesperada queda registrada como resultado `['crash' => ...]` para que la prueba la vea.
     *
     * @param  \Closure(int): array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    protected function storm(int $workers, \Closure $attempt): array
    {
        $raw = Parallel::run($workers, $attempt);

        return array_map(static fn (array $r): array => $r['ok'] ? $r['value'] : ['crash' => $r['class'].': '.$r['message']], $raw);
    }

    /** Cuenta los resultados por una clave. @param list<array<string, mixed>> $results @return array<string, int> */
    protected function tally(array $results, string $key): array
    {
        $out = [];
        foreach ($results as $r) {
            $k = (string) ($r[$key] ?? ($r['crash'] ?? '?'));
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }
}
