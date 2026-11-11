<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\HostSimProcess;
use Tests\Support\OutboxHostFixture;

/**
 * Medición del vaciado de la bandeja de salida (prize_outbox) contra el simulador del host con latencia fija. No es una prueba de
 * corrección: solo corre con GAMIF_BENCH=1 y escribe una fila por configuración.
 *
 *   docker compose run --rm -e GAMIF_BENCH=1 -e BENCH_SCENARIO=baseline backend php artisan test --filter=OutboxThroughputBenchTest
 *   docker compose run --rm -e GAMIF_BENCH=1 -e BENCH_SCENARIO=mejora   backend php artisan test --filter=OutboxThroughputBenchTest
 *
 * Variables: BENCH_N (avisos, 2000), BENCH_LATENCY_MS (20), BENCH_TICK (segundos entre corridas del planificador, 10).
 *  - baseline: 1 despachador con la configuración de siempre (limit 100, una corrida cada BENCH_TICK s).
 *  - mejora:   1, 2 y 4 despachadores; cada corrida sigue mientras queden avisos vencidos (limit alto, tope de 300 s).
 */
final class OutboxThroughputBenchTest extends ConcurrencyTestCase
{
    use OutboxHostFixture;

    /** @return array<string, array{string, int}> */
    public static function configs(): array
    {
        return [
            'baseline: 1 despachador, limit 100, cada 10 s' => ['baseline', 1],
            'mejora: 1 despachador, drenaje continuo' => ['mejora', 1],
            'mejora: 2 despachadores, drenaje continuo' => ['mejora', 2],
            'mejora: 4 despachadores, drenaje continuo' => ['mejora', 4],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configs')]
    public function test_segundos_para_vaciar_la_bandeja(string $scenario, int $workers): void
    {
        if (getenv('GAMIF_BENCH') !== '1') {
            $this->markTestSkipped('Medición: ejecutar con GAMIF_BENCH=1.');
        }
        if (getenv('BENCH_SCENARIO') !== false && getenv('BENCH_SCENARIO') !== $scenario) {
            $this->markTestSkipped("Escenario $scenario no pedido (BENCH_SCENARIO).");
        }
        $n = (int) (getenv('BENCH_N') ?: 2000);
        $latency = (int) (getenv('BENCH_LATENCY_MS') ?: 20);
        $tick = (float) (getenv('BENCH_TICK') ?: 10);

        // Parallel lee el resultado de los hijos con el tiempo de espera de sockets (60 s por defecto): las corridas largas lo superarían.
        ini_set('default_socket_timeout', '1800');
        $sim = HostSimProcess::start(latencyMs: $latency);
        try {
            $this->registerHost($sim);
            $this->seedPendingNotices($n);

            $results = $this->storm($workers, function () use ($scenario, $n, $tick): array {
                $d = $this->newDispatcher();
                $start = microtime(true);
                if ($scenario === 'baseline') {
                    // Lo que hace hoy el planificador: una corrida de a lo sumo 100 avisos cada $tick segundos.
                    do {
                        $t = microtime(true);
                        $d->dispatchDue(100);
                        $left = DB::table('prize_outbox')->where('status', 'pending')->count();
                        if ($left > 0 && ($wait = $tick - (microtime(true) - $t)) > 0) {
                            usleep((int) ($wait * 1_000_000));
                        }
                    } while ($left > 0);
                } else {
                    $d->dispatchDue($n, 300.0);
                }

                return ['start' => $start, 'end' => microtime(true)];
            });

            $this->assertArrayNotHasKey('crash', $results[0], json_encode($results));
            $seconds = max(array_column($results, 'end')) - min(array_column($results, 'start'));
            $s = $sim->summary();
            $sent = DB::table('prize_outbox')->where('status', 'sent')->count();
            fwrite(STDOUT, sprintf(
                "\n%-46s avisos=%d  segundos=%.1f  avisos/s=%.1f  enviados=%d  acreditado=%d  duplicados=%d  rechazados=%d  (latencia %d ms)\n",
                $scenario.': '.$workers.' despachador(es)', $n, $seconds, $n / $seconds, $sent, $s['credited'], $s['duplicates'], $s['rejected'], $latency,
            ));
            $this->assertSame($n, $sent);
            $this->assertSame(0, $s['duplicates']);
            $this->assertSame($n * 10000, $s['credited']);
        } finally {
            $sim->stop();
        }
    }
}
