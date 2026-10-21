<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Codes\CodeExportStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;

/** `audit.export` con control dual y entrega única (SPEC-003 P2b-2b, RF-38, riesgo R3 de SPEC-002). */
final class AuditExportApprovalTest extends AdminTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
        $this->app->instance(BeaconClient::class, $this->f->beacon);
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        foreach (glob((new CodeExportStore)->dir().'/codes-*') ?: [] as $file) {
            unlink($file);
        }
    }

    private function as(array $admin): void
    {
        $this->api('POST', '/auth/logout');
        $this->login($admin);
    }

    private function revealed(): string
    {
        $id = $this->f->active();
        $this->f->lifecycle()->close($id, Fixtures::ACTOR);
        $this->f->lifecycle()->reveal($id, Fixtures::ACTOR);

        return $id;
    }

    private function approved(array $op, array $boss, string $batch): int
    {
        $this->as($op);
        $r = $this->api('POST', '/approvals', ['action' => 'audit.export', 'params' => ['batchId' => $batch]]);
        $this->assertSame(201, $r->status(), $r->getContent());
        $id = (int) $r->json('id');
        $this->as($boss);
        $this->api('POST', "/approvals/$id/approve", ['code' => $this->nextCode($boss['secret'])])->assertNoContent();
        $this->as($op);

        return $id;
    }

    public function test_flujo_completo_exportar_y_descargar_una_vez(): void
    {
        $op = $this->makeAdmin('operator');
        $boss = $this->makeAdmin('superadmin');
        $batch = $this->revealed();
        $id = $this->approved($op, $boss, $batch);

        $r = $this->api('POST', "/approvals/$id/execute");
        $this->assertSame(200, $r->status(), $r->getContent());
        $this->assertNotNull($r->json('anchor.head_hash'));

        $d = $this->get(self::PREFIX."/approvals/$id/download")->assertOk();
        $body = $d->streamedContent();
        $this->assertSame($r->json('sha256'), hash('sha256', $body));
        $this->assertStringContainsString('application/json', $d->headers->get('Content-Type'));
        $export = json_decode($body, true);
        $this->assertSame($batch, $export['batch_id']);
        $this->assertNotEmpty($export['seed']);
        $this->assertSame([], glob((new CodeExportStore)->dir().'/codes-*') ?: []);
        $this->assertSame(410, $this->get(self::PREFIX."/approvals/$id/download")->status());

        foreach (['audit.exported', 'audit.downloaded'] as $a) {
            $this->assertNotNull(DB::selectOne('SELECT 1 AS x FROM audit_log WHERE action = ?', [$a]), $a);
        }
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_solo_un_lote_revelado_se_puede_pedir(): void
    {
        $op = $this->makeAdmin('operator');
        $this->as($op);
        foreach ([$this->f->draft(), $this->f->committed(), $this->f->active()] as $batch) {
            $this->assertSame(422, $this->api('POST', '/approvals', ['action' => 'audit.export', 'params' => ['batchId' => $batch]])->status());
        }
        $this->assertSame(422, $this->api('POST', '/approvals', ['action' => 'audit.export', 'params' => []])->status());
    }

    public function test_el_auditor_no_la_pide_ni_la_descarga_y_otro_usuario_tampoco(): void
    {
        $op = $this->makeAdmin('operator');
        $boss = $this->makeAdmin('superadmin');
        $id = $this->approved($op, $boss, $this->revealed());
        $this->api('POST', "/approvals/$id/execute")->assertOk();

        $this->as($boss);
        $this->assertSame(404, $this->get(self::PREFIX."/approvals/$id/download")->status());
        $this->as($this->makeAdmin('auditor'));
        $this->assertSame(403, $this->get(self::PREFIX."/approvals/$id/download")->status());
        $this->assertSame(403, $this->api('POST', '/approvals', ['action' => 'audit.export', 'params' => ['batchId' => $this->revealed()]])->status());
    }

    public function test_sin_aprobacion_no_se_escribe_nada(): void
    {
        $op = $this->makeAdmin('operator');
        $this->makeAdmin('superadmin');
        $this->as($op);
        $id = (int) $this->api('POST', '/approvals', ['action' => 'audit.export', 'params' => ['batchId' => $this->revealed()]])->json('id');
        $this->assertSame(409, $this->api('POST', "/approvals/$id/execute")->status());
        $this->assertSame([], glob((new CodeExportStore)->dir().'/codes-*') ?: []);
    }

    public function test_un_archivo_alterado_no_se_entrega(): void
    {
        $op = $this->makeAdmin('operator');
        $boss = $this->makeAdmin('superadmin');
        $id = $this->approved($op, $boss, $this->revealed());
        $this->api('POST', "/approvals/$id/execute")->assertOk();
        file_put_contents((new CodeExportStore)->path($id, 'audit'), ' ', FILE_APPEND);

        $this->assertSame('exportTampered', $this->get(self::PREFIX."/approvals/$id/download")->json('error.code'));
    }
}
