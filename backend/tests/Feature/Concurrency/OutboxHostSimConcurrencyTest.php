<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\HostSimProcess;
use Tests\Support\OutboxHostFixture;

/**
 * Varios despachadores (procesos reales) contra el simulador del host REAL (HTTP firmado, con 5 ms de latencia para que se crucen):
 * cada aviso se entrega exactamente una vez y la billetera del simulador no tiene duplicados ni créditos de más.
 */
final class OutboxHostSimConcurrencyTest extends ConcurrencyTestCase
{
    use OutboxHostFixture;

    private const N = 120;

    /** @return array<string, array{int}> */
    public static function dispatchers(): array
    {
        return ['1 despachador' => [1], '2 despachadores' => [2], '4 despachadores' => [4]];
    }

    /** @dataProvider dispatchers */
    #[\PHPUnit\Framework\Attributes\DataProvider('dispatchers')]
    public function test_cada_aviso_se_entrega_una_sola_vez_con_n_despachadores(int $workers): void
    {
        if (HostSimProcess::dir() === null) {
            $this->markTestSkipped('Falta la carpeta del simulador (HOST_SIM_DIR o /host-sim).');
        }
        $sim = HostSimProcess::start(latencyMs: 5);
        try {
            $this->registerHost($sim);
            $this->seedPendingNotices(self::N);

            $results = $this->storm($workers, function (): array {
                $d = $this->newDispatcher();
                $sent = 0;
                do {
                    $r = $d->dispatchDue(10);
                    $sent += $r['sent'];
                } while ($r['sent'] + $r['retry'] + $r['failed'] > 0);

                return ['status' => 'done', 'sent' => $sent];
            });

            $this->assertSame(['done' => $workers], $this->tally($results, 'status'));
            $this->assertSame(self::N, array_sum(array_column($results, 'sent')), 'entre todos entregaron exactamente N avisos');
            $this->assertSame(self::N, DB::table('prize_outbox')->where('status', 'sent')->where('attempts', 1)->count(), 'todos enviados al primer intento');
            $s = $sim->summary();
            $this->assertSame(['credited' => self::N * 10000, 'events' => self::N, 'duplicates' => 0, 'rejected' => 0], $s, 'billetera del simulador sin duplicados');
        } finally {
            $sim->stop();
        }
    }
}
