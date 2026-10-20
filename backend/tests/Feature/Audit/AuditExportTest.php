<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditAnchor;
use App\Domain\Audit\AuditExporter;
use App\Domain\Audit\AuditLog;
use App\Domain\Batches\InvalidBatch;
use App\Engine\Bytes;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

final class AuditExportTest extends DbTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
    }

    private function revealedBatch(): string
    {
        $id = $this->f->active(Fixtures::pool(none: 12, p10k: 4, p50k: 2, p100k: 1));
        $this->f->lifecycle()->close($id, 'ana');
        $this->f->lifecycle()->reveal($id, 'ana');

        return $id;
    }

    private function tmp(string $name): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('gamif-audit-', true).'-'.$name;
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        return $path;
    }

    public function test_solo_se_exporta_un_lote_revelado(): void
    {
        $exporter = new AuditExporter;
        $active = $this->f->active();
        try {
            $exporter->export($active);
            $this->fail('debía rechazar un lote activo');
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString('revelado', $e->getMessage());
        }
        $this->expectException(InvalidBatch::class);
        $exporter->export('00000000-0000-0000-0000-000000000000');
    }

    public function test_la_exportacion_trae_el_lote_y_la_bitacora_completa(): void
    {
        $id = $this->revealedBatch();
        $export = (new AuditExporter)->export($id);

        $this->assertSame(2, $export['version']);
        $this->assertCount(19, $export['assignments']);
        $this->assertCount(19, $export['code_hashes']);
        $row = $this->f->batchRow($id);
        $this->assertSame($row->commitment, $export['commitment']);
        $this->assertSame($row->seed_revealed, $export['seed']);

        $this->assertCount(DB::table('audit_log')->count(), $export['audit_log']);
        $ids = array_column($export['audit_log'], 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'en orden de la cadena');
    }

    /** Recomputa la cadena EN LA PRUEBA, sin pasar por AuditLog: así se comprueba que el formato exportado alcanza para verificarla. */
    public function test_la_cadena_exportada_se_puede_recomputar_sin_la_base(): void
    {
        $export = (new AuditExporter)->export($this->revealedBatch());

        $prev = AuditLog::GENESIS;
        foreach ($export['audit_log'] as $e) {
            $this->assertSame(bin2hex($prev), $e['prev_hash']);
            $calc = hash('sha256',
                'gamif/v1/audit'.$prev.Bytes::lp($e['actor']).Bytes::lp($e['action']).Bytes::lp($e['subject_type'])
                .Bytes::lp($e['subject_id']).Bytes::lp($e['data']).Bytes::be64($e['at_micros']), true);
            $this->assertSame(bin2hex($calc), $e['hash'], "entrada {$e['id']}");
            $prev = $calc;
        }
    }

    public function test_el_ancla_es_la_ultima_entrada(): void
    {
        $this->assertNull((new AuditAnchor)->current(), 'bitácora vacía: nada que anclar');

        $export = (new AuditExporter)->export($this->revealedBatch());
        $anchor = (new AuditAnchor)->current();
        $last = end($export['audit_log']);

        $this->assertSame($last['id'], $anchor['head_id']);
        $this->assertSame($last['hash'], $anchor['head_hash']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $anchor['created_at']);
    }

    public function test_el_comando_escribe_la_exportacion_y_el_ancla_y_no_sobrescribe(): void
    {
        $id = $this->revealedBatch();
        $out = $this->tmp('export.json');
        $anchor = $this->tmp('ancla.json');

        $this->artisan('gamification:audit:export', ['batch' => $id, '--out' => $out, '--anchor' => $anchor])->assertSuccessful();

        $export = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        $a = json_decode((string) file_get_contents($anchor), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($id, $export['batch_id']);
        $this->assertSame(end($export['audit_log'])['hash'], $a['head_hash']);

        $before = file_get_contents($out);
        $this->artisan('gamification:audit:export', ['batch' => $id, '--out' => $out])->assertFailed();
        $this->assertSame($before, file_get_contents($out), 'no se sobrescribe');

        $this->artisan('gamification:audit:export', ['batch' => $this->f->active(), '--out' => $this->tmp('x.json')])->assertFailed();
    }

    public function test_el_comando_de_ancla_escribe_y_no_sobrescribe(): void
    {
        $this->artisan('gamification:audit:anchor', ['--out' => $this->tmp('vacia.json')])->assertFailed();

        $this->revealedBatch();
        $path = $this->tmp('ancla.json');
        $this->artisan('gamification:audit:anchor', ['--out' => $path])->assertSuccessful();
        $this->assertSame((new AuditAnchor)->current()['head_hash'], json_decode((string) file_get_contents($path), true)['head_hash']);
        $this->artisan('gamification:audit:anchor', ['--out' => $path])->assertFailed();
    }

    /**
     * Regenera el fixture que usan las pruebas del verificador Python (una exportación REAL de este código):
     *   UPDATE_VERIFIER_FIXTURE=1 docker compose run --rm -e UPDATE_VERIFIER_FIXTURE=1 backend php artisan test --filter=fixture
     * Escribe en backend/storage/app/ (visible desde el host); luego se mueve a verifier/fixtures/.
     */
    public function test_regenera_el_fixture_del_verificador_si_se_pide(): void
    {
        if (getenv('UPDATE_VERIFIER_FIXTURE') !== '1') {
            $this->markTestSkipped('Solo con UPDATE_VERIFIER_FIXTURE=1.');
        }
        $export = (new AuditExporter)->export($this->revealedBatch());
        $anchor = (new AuditAnchor)->current();
        $dir = storage_path('app');
        file_put_contents("$dir/export-sample.json", json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        file_put_contents("$dir/anchor-sample.json", json_encode($anchor, JSON_PRETTY_PRINT)."\n");
        $this->assertFileExists("$dir/export-sample.json");
    }
}
