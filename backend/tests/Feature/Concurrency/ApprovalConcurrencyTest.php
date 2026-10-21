<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Admin\AdminAccounts;
use App\Domain\Approvals\ApprovalException;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;

/** T14: la misma aprobación ejecutada por muchos procesos a la vez se aplica EXACTAMENTE una vez. */
final class ApprovalConcurrencyTest extends ConcurrencyTestCase
{
    public function test_t14_treinta_ejecuciones_simultaneas_de_una_aprobacion_aplican_el_cambio_una_sola_vez(): void
    {
        $accounts = new AdminAccounts;
        $a = $accounts->create('a@ejemplo.com', 'A', 'superadmin', 'test');
        $b = $accounts->create('b@ejemplo.com', 'B', 'superadmin', 'test');
        $id = (int) DB::selectOne(
            "INSERT INTO approvals (action, subject_type, subject_id, params, status, requested_by, expires_at, approved_by, approved_at)
             VALUES ('setting.change', 'setting', 'token.max_lifetime_seconds', '{\"key\":\"token.max_lifetime_seconds\",\"value\":900}'::jsonb,
                     'approved', ?, now() + interval '1 hour', ?, now()) RETURNING id", [$a['id'], $b['id']])->id;
        $user = (object) ['id' => $a['id'], 'role' => 'superadmin'];

        $results = $this->storm(30, function (int $i) use ($id, $user): array {
            try {
                app(ApprovalService::class)->execute($user, $id);

                return ['status' => 'ok'];
            } catch (ApprovalException $e) {
                return ['status' => $e->errorCode];
            }
        });

        $this->assertSame(['ok' => 1, 'wrongState' => 29], $this->tally($results, 'status'), json_encode($this->tally($results, 'status')));
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM settings_history')->n, 'el cambio se aplicó una vez');
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM audit_log WHERE action = 'approval.executed'")->n);
        $this->assertSame('executed', DB::table('approvals')->where('id', $id)->value('status'));
        $this->assertNull((new AuditLog)->verifyChain());
    }
}
