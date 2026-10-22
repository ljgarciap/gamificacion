<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Audit\AuditLog;
use App\Domain\Redemption\RedeemFailure;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RedeemFixtures;

/**
 * D7 (SPEC-002) / RNF-35: medición del techo de canjes por segundo con la bitácora encadenada bajo candado global.
 * No es una prueba de corrección: solo corre con GAMIF_BENCH=1 y escribe la tabla en la salida. Los resultados vigentes están en docs/hardening-p9.md.
 *   docker compose run --rm -e GAMIF_BENCH=1 backend php artisan test --filter=RedeemThroughputBenchTest
 */
final class RedeemThroughputBenchTest extends ConcurrencyTestCase
{
    private const PER_WORKER = 20;

    public function test_techo_de_canjes_por_segundo_segun_la_concurrencia(): void
    {
        if (getenv('GAMIF_BENCH') !== '1') {
            $this->markTestSkipped('Medición: ejecutar con GAMIF_BENCH=1.');
        }
        $levels = [1, 5, 10, 25, 50];
        $need = array_sum($levels) * self::PER_WORKER;
        $rf = new RedeemFixtures;
        $b = $rf->activeBatch(Fixtures::pool(none: $need - 120, p10k: 100, p50k: 20));
        $svc = $rf->service();
        $codes = $b['codes'];
        $offset = 0;

        $lines = [sprintf("\n%-9s %-9s %-12s %-12s %-12s", 'procesos', 'canjes', 'canjes/s', 'p50 ms', 'p95 ms')];
        foreach ($levels as $w) {
            $slice = array_slice($codes, $offset, $w * self::PER_WORKER);
            $offset += $w * self::PER_WORKER;
            $results = $this->storm($w, function (int $i) use ($svc, $slice, $w, $offset): array {
                $times = [];
                $start = microtime(true);
                for ($k = 0; $k < self::PER_WORKER; $k++) {
                    $code = $slice[$i * self::PER_WORKER + $k];
                    $t = microtime(true);
                    try {
                        $svc->redeem("host-a:b{$w}u{$i}k{$k}", sprintf('bench-%d-%d-%03d-%03d', $w, $offset, $i, $k), $code);
                    } catch (RedeemFailure $e) {
                        return ['crash' => 'RedeemFailure '.$e->reason];
                    }
                    $times[] = (microtime(true) - $t) * 1000;
                }

                return ['start' => $start, 'end' => microtime(true), 'times' => $times];
            });
            $crash = array_values(array_filter($results, fn ($r) => isset($r['crash'])));
            $this->assertSame([], $crash, 'ningún canje debe fallar');
            $wall = max(array_column($results, 'end')) - min(array_column($results, 'start'));
            $all = array_merge(...array_column($results, 'times'));
            sort($all);
            $lines[] = sprintf('%-9d %-9d %-12.1f %-12.1f %-12.1f', $w, count($all), count($all) / $wall, $all[(int) (count($all) * 0.5)], $all[(int) (count($all) * 0.95)]);
        }
        fwrite(STDERR, implode("\n", $lines)."\n");

        $this->assertSame($need, (int) DB::table('redemptions')->count());
        $this->assertNull((new AuditLog)->verifyChain(), 'la cadena sigue íntegra tras la carga');
    }
}
