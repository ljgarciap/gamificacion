<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Audit\AuditExporter;
use App\Domain\Codes\CodeGenerator;
use App\Domain\Crypto\CodeHasher;
use Tests\ConcurrencyTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RedeemFixtures;

/**
 * RNF-35 / SPEC-003 P9: memoria máxima por etapa con el tope de códigos del lote (Pool::MAX_TOTAL = 1 000 000).
 * Solo corre con GAMIF_BENCH=1 (tarda varios minutos y llena la base temporal). Resultados vigentes: docs/hardening-p9.md.
 *   docker compose run --rm -e GAMIF_BENCH=1 backend php -d memory_limit=-1 artisan test --filter=MemoryCeilingBenchTest
 */
final class MemoryCeilingBenchTest extends ConcurrencyTestCase
{
    public function test_memoria_y_tiempo_por_etapa_con_un_millon_de_codigos(): void
    {
        if (getenv('GAMIF_BENCH') !== '1') {
            $this->markTestSkipped('Medición: ejecutar con GAMIF_BENCH=1.');
        }
        $n = (int) (getenv('GAMIF_BENCH_CODES') ?: 1_000_000);
        $f = new Fixtures;
        $lines = [sprintf("\n%-26s %-10s %-12s", 'etapa', 'segundos', 'pico MB')];
        $stage = function (string $name, \Closure $fn) use (&$lines) {
            memory_reset_peak_usage();
            $t = microtime(true);
            $r = $fn();
            $lines[] = sprintf('%-26s %-10.1f %-12.1f', $name, microtime(true) - $t, memory_get_peak_usage(true) / 1048576);

            return $r;
        };

        $campaign = $f->campaign();
        $batch = $stage('crear borrador', fn () => $f->lifecycle()->createDraft($campaign, 'Tope', Fixtures::pool(none: $n - 1000, p10k: 900, p50k: 100), 'bench'));
        $path = sys_get_temp_dir().'/bench-'.bin2hex(random_bytes(4)).'.csv';
        $stage('generar códigos + CSV', fn () => (new CodeGenerator($f->lifecycle(), new CodeHasher, $f->audit))->generate($batch, 'TOPE', $path, 'bench'));
        @unlink($path);
        $stage('comprometer', fn () => $f->lifecycle()->commit($batch, 'bench'));
        $stage('activar', fn () => $f->activate($batch));
        $f->lifecycle()->close($batch, 'bench');
        $stage('revelar', fn () => $f->lifecycle()->reveal($batch, 'bench'));
        $export = $stage('exportar auditoría', fn () => (new AuditExporter)->export($batch));
        $stage('serializar exportación', fn () => strlen(json_encode($export)));
        fwrite(STDERR, implode("\n", $lines)."\n");

        $this->assertCount($n, $export['assignments'] ?? $export['codes'] ?? range(1, $n));
    }
}
