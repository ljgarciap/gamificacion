<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Fixtures;

/** Resumen y outbox del panel (SPEC-003 RF-39, RF-42). */
final class OperationsTest extends AdminTestCase
{
    /** Crea una redención con su aviso en el estado dado. */
    private function notice(string $status, int $attempts = 0): int
    {
        $f = new Fixtures;
        $this->app->instance(\App\Domain\Beacon\BeaconClient::class, $f->beacon);
        $batch = $f->active();
        $codeId = (int) DB::table('codes')->where('batch_id', $batch)->whereNull('redeemed_at')->orderBy('idx')->value('id');
        DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'u-1' WHERE id = ?", [$codeId]);
        $rid = (string) Str::uuid();
        DB::insert("INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response) VALUES (?, ?, ?, 'u-1', ?, 'p10k', '{}')", [$rid, $codeId, $batch, Str::random(16)]);
        $payload = json_encode(['redemption_id' => $rid, 'user_id' => 'u-1', 'batch_id' => $batch, 'prize' => ['id' => 'p10k', 'type' => 'money']]);
        $sent = $status === 'sent' ? 'now()' : 'NULL';

        return (int) DB::selectOne("INSERT INTO prize_outbox (redemption_id, payload, status, attempts, sent_at, last_error) VALUES (?, ?::jsonb, ?, ?, $sent, 'host caído') RETURNING id", [$rid, $payload, $status, $attempts])->id;
    }

    public function test_el_listado_filtra_cuenta_y_muestra_el_error(): void
    {
        $this->login($this->makeAdmin('auditor'));
        $failed = $this->notice('failed', 5);
        $this->notice('sent', 1);
        $this->notice('pending');

        $all = $this->api('GET', '/outbox')->assertOk()->json();
        $this->assertSame(['pending' => 1, 'sent' => 1, 'failed' => 1], $all['counts']);
        $this->assertSame(3, $all['total']);
        $f = $this->api('GET', '/outbox?status=failed')->json();
        $this->assertSame([$failed, 'host caído', 'u-1', 'p10k'], [$f['items'][0]['id'], $f['items'][0]['lastError'], $f['items'][0]['userId'], $f['items'][0]['prizeId']]);
        $this->assertSame(422, $this->api('GET', '/outbox?status=otro')->status());
        $this->assertSame(200, $this->api('GET', '/outbox?perPage=99999')->json('perPage'));
    }

    public function test_reintentar_reencola_solo_los_agotados_y_deja_rastro(): void
    {
        $this->login($this->makeAdmin('operator'));
        $failed = $this->notice('failed', 5);
        $sent = $this->notice('sent');
        $pending = $this->notice('pending');

        $this->api('POST', "/outbox/$failed/retry")->assertNoContent();
        $row = DB::table('prize_outbox')->where('id', $failed)->first();
        $this->assertSame(['pending', 0], [$row->status, (int) $row->attempts]);
        $this->assertNotNull(DB::selectOne("SELECT 1 AS x FROM audit_log WHERE action = 'outbox.retried' AND subject_id = ?", [(string) $failed]));
        $this->assertNull((new AuditLog)->verifyChain());

        $this->assertSame(409, $this->api('POST', "/outbox/$sent/retry")->status());
        $this->assertSame(409, $this->api('POST', "/outbox/$pending/retry")->status());
        $this->assertSame(409, $this->api('POST', "/outbox/$failed/retry")->status(), 'ya no está agotado');
        $this->assertSame('sent', DB::table('prize_outbox')->where('id', $sent)->value('status'));
        $this->assertSame(404, $this->api('POST', '/outbox/999999/retry')->status());
    }

    public function test_el_auditor_mira_pero_no_reintenta(): void
    {
        $failed = $this->notice('failed', 5);
        $this->login($this->makeAdmin('auditor'));
        $this->api('GET', '/outbox')->assertOk();
        $this->api('POST', "/outbox/$failed/retry")->assertStatus(403);
        $this->assertSame('failed', DB::table('prize_outbox')->where('id', $failed)->value('status'));
    }

    public function test_el_resumen_cuenta_lo_que_hay(): void
    {
        $this->login($this->makeAdmin('auditor'));
        $f = new Fixtures;
        $f->draft();
        $this->notice('failed', 5);
        $this->notice('pending');

        $d = $this->api('GET', '/dashboard')->assertOk()->json();
        $this->assertSame(2, $d['batches']['active']);
        $this->assertGreaterThanOrEqual(1, $d['batches']['draft']);
        $this->assertSame([1, 1], [$d['outbox']['failed'], $d['outbox']['pending']]);
        $this->assertNotNull($d['outbox']['oldestPendingAt']);
        $this->assertSame(2, $d['redemptions']['total']);
        $this->assertSame(1, $d['admins']);
        $this->assertSame(['pending' => 0, 'awaitingExecution' => 0], $d['approvals']);
    }
}
