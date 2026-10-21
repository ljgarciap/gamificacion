<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Codes\CodeExportStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;

/** `codes.generate` con control dual y descarga única (SPEC-003 P2b-2; el CSV es la única copia en claro de los códigos). */
final class CodeGenerationApprovalTest extends AdminTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
        $this->app->instance(BeaconClient::class, $this->f->beacon);
        foreach (glob((new CodeExportStore)->dir().'/codes-*') ?: [] as $file) {
            unlink($file);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob((new CodeExportStore)->dir().'/codes-*') ?: [] as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    /** @return array{0: array, 1: array} operador y jefe */
    private function pair(): array
    {
        return [$this->makeAdmin('operator'), $this->makeAdmin('superadmin')];
    }

    private function as(array $admin): void
    {
        $this->api('POST', '/auth/logout');
        $this->login($admin);
    }

    /** Solicita (operador), aprueba (jefe) y deja al operador con sesión. */
    private function approved(array $op, array $boss, string $batch, string $prefix = 'SG'): int
    {
        $this->as($op);
        $r = $this->api('POST', '/approvals', ['action' => 'codes.generate', 'params' => ['batchId' => $batch, 'prefix' => $prefix]]);
        $this->assertSame(201, $r->status(), $r->getContent());
        $id = (int) $r->json('id');
        $this->as($boss);
        $this->api('POST', "/approvals/$id/approve", ['code' => $this->nextCode($boss['secret'])])->assertNoContent();
        $this->as($op);

        return $id;
    }

    public function test_flujo_completo_generar_descargar_una_vez_y_comprometer(): void
    {
        [$op, $boss] = $this->pair();
        $batch = $this->f->draft();
        $id = $this->approved($op, $boss, $batch);

        $r = $this->api('POST', "/approvals/$id/execute");
        $this->assertSame(200, $r->status(), $r->getContent());
        $this->assertSame(10, $r->json('count'));
        $this->assertSame(10, DB::table('codes')->where('batch_id', $batch)->count());

        $d = $this->get(self::PREFIX."/approvals/$id/download");
        $d->assertOk();
        $body = $d->streamedContent();
        $this->assertSame($r->json('sha256'), hash('sha256', $body));
        $this->assertSame($r->json('sha256'), $d->headers->get('X-Content-SHA256'));
        $lines = explode("\n", trim($body));
        $this->assertSame('codigo', $lines[0]);
        $this->assertCount(11, $lines);
        $this->assertStringContainsString('no-store', $d->headers->get('Cache-Control'));
        $this->assertSame([], glob((new CodeExportStore)->dir().'/codes-*') ?: [], 'el archivo se destruye tras enviarse');

        // Segunda descarga: ya no existe.
        $again = $this->get(self::PREFIX."/approvals/$id/download");
        $this->assertSame(410, $again->status());

        // Los códigos entregados sirven: se encuentran y el lote puede comprometerse.
        $this->api('POST', '/codes/lookup', ['code' => $lines[1]])->assertOk();
        $this->api('POST', "/batches/$batch/commit")->assertOk();
        $this->assertNull((new AuditLog)->verifyChain());
        foreach (['approval.executed', 'codes.downloaded', 'batch.codes_generated'] as $a) {
            $this->assertNotNull(DB::selectOne('SELECT 1 AS x FROM audit_log WHERE action = ?', [$a]), $a);
        }
        $this->assertStringNotContainsString($lines[1], json_encode(DB::table('audit_log')->pluck('data')->all()), 'la bitácora no guarda códigos');
    }

    public function test_solo_el_solicitante_descarga(): void
    {
        [$op, $boss] = $this->pair();
        $id = $this->approved($op, $boss, $this->f->draft());
        $this->api('POST', "/approvals/$id/execute")->assertOk();

        $this->as($boss);
        $this->assertSame(404, $this->get(self::PREFIX."/approvals/$id/download")->status());
        $aud = $this->makeAdmin('auditor');
        $this->as($aud);
        $this->assertSame(403, $this->get(self::PREFIX."/approvals/$id/download")->status());
        $this->assertCount(1, glob((new CodeExportStore)->dir().'/codes-*'), 'el archivo sigue esperando a su dueño');

        $this->as($op);
        $this->get(self::PREFIX."/approvals/$id/download")->assertOk();
    }

    public function test_sin_aprobar_no_se_genera_nada(): void
    {
        [$op, $boss] = $this->pair();
        $batch = $this->f->draft();
        $this->as($op);
        $id = (int) $this->api('POST', '/approvals', ['action' => 'codes.generate', 'params' => ['batchId' => $batch, 'prefix' => 'SG']])->json('id');
        $this->assertSame(409, $this->api('POST', "/approvals/$id/execute")->status());
        $this->assertSame(0, DB::table('codes')->where('batch_id', $batch)->count());
        $this->assertSame(410, $this->get(self::PREFIX."/approvals/$id/download")->status(), 'sin ejecutar no hay descarga');
    }

    public function test_la_descarga_caduca_y_el_archivo_se_destruye(): void
    {
        [$op, $boss] = $this->pair();
        $id = $this->approved($op, $boss, $this->f->draft());
        $this->api('POST', "/approvals/$id/execute")->assertOk();

        $this->advance(61 * 60);
        $this->assertSame(410, $this->get(self::PREFIX."/approvals/$id/download")->status());
        $file = (new CodeExportStore)->path($id);
        touch($file, time() - 7200);
        $this->assertSame(1, (new CodeExportStore)->purgeExpired());
        $this->assertFileDoesNotExist($file);
    }

    public function test_un_archivo_alterado_no_se_entrega(): void
    {
        [$op, $boss] = $this->pair();
        $id = $this->approved($op, $boss, $this->f->draft());
        $this->api('POST', "/approvals/$id/execute")->assertOk();
        file_put_contents((new CodeExportStore)->path($id), "SG-AAAA-AAAA-AAA\n", FILE_APPEND);

        $r = $this->get(self::PREFIX."/approvals/$id/download");
        $this->assertSame(500, $r->status());
        $this->assertSame('exportTampered', $r->json('error.code'));
        $this->assertNull(DB::selectOne("SELECT 1 AS x FROM audit_log WHERE action = 'codes.downloaded'"));
    }

    public function test_reclamar_el_archivo_es_atomico(): void
    {
        $s = new CodeExportStore;
        file_put_contents($s->path(99), "codigo\n");
        $first = $s->claim(99);
        $this->assertNotNull($first);
        $this->assertNull($s->claim(99));
        unlink($first);
    }

    public function test_validacion_de_la_solicitud(): void
    {
        [$op, $boss] = $this->pair();
        $this->as($op);
        $draft = $this->f->draft();
        $withCodes = $this->f->draftWithCodes();
        $committed = $this->f->committed();

        foreach ([
            ['batchId' => $draft, 'prefix' => 'a'], ['batchId' => $draft, 'prefix' => 'SG-1'], ['batchId' => $draft], ['prefix' => 'SG'],
            ['batchId' => $withCodes, 'prefix' => 'SG'], ['batchId' => $committed, 'prefix' => 'SG'],
        ] as $params) {
            $r = $this->api('POST', '/approvals', ['action' => 'codes.generate', 'params' => $params]);
            $this->assertSame(422, $r->status(), json_encode($params));
        }
        $this->assertSame(404, $this->api('POST', '/approvals', ['action' => 'codes.generate', 'params' => ['batchId' => '00000000-0000-4000-8000-000000000000', 'prefix' => 'SG']])->status());
        $this->as($this->makeAdmin('auditor'));
        $this->assertSame(403, $this->api('POST', '/approvals', ['action' => 'codes.generate', 'params' => ['batchId' => $draft, 'prefix' => 'SG']])->status());
    }

    public function test_si_el_lote_cambia_antes_de_ejecutar_no_queda_ni_archivo_ni_codigos(): void
    {
        [$op, $boss] = $this->pair();
        $batch = $this->f->draft();
        $id = $this->approved($op, $boss, $batch);
        $this->f->addCodes($batch, 1); // alguien agrega un código entre la aprobación y la ejecución

        $r = $this->api('POST', "/approvals/$id/execute");
        $this->assertSame(422, $r->status(), $r->getContent());
        $this->assertSame(1, DB::table('codes')->where('batch_id', $batch)->count());
        $this->assertSame([], glob((new CodeExportStore)->dir().'/codes-*') ?: []);
        $this->assertSame('approved', DB::table('approvals')->where('id', $id)->value('status'));
    }

    public function test_si_falla_la_ejecucion_despues_de_escribir_el_archivo_no_queda_el_archivo(): void
    {
        [$op, $boss] = $this->pair();
        $batch = $this->f->draft();
        $id = $this->approved($op, $boss, $batch);
        // Falla DESPUÉS de escribir el archivo: un disparador (dentro de la transacción de la prueba) rechaza marcar la solicitud como ejecutada.
        DB::unprepared("CREATE FUNCTION zz_fail_exec() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN RAISE EXCEPTION 'fallo simulado'; END \$f\$;
            CREATE TRIGGER zz_fail_exec BEFORE UPDATE ON approvals FOR EACH ROW WHEN (NEW.status = 'executed') EXECUTE FUNCTION zz_fail_exec()");

        $this->assertSame(500, $this->api('POST', "/approvals/$id/execute")->status());
        $this->assertFalse(is_file((new CodeExportStore)->path($id)), 'el archivo no puede sobrevivir a la reversión');
        $this->assertSame(0, DB::table('codes')->where('batch_id', $batch)->count(), 'los códigos se revirtieron');
        $this->assertSame([], glob((new CodeExportStore)->dir().'/codes-*') ?: [], 'y el archivo con códigos inexistentes no queda');
    }
}
