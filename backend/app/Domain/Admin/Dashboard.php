<?php

namespace App\Domain\Admin;

use Illuminate\Support\Facades\DB;

/** Resumen operativo del panel (SPEC-003 RF-42): solo conteos, nada que no se vea ya en las demás pantallas. */
final class Dashboard
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        $batches = array_fill_keys(['draft', 'committed', 'active', 'closed', 'revealed'], 0);
        foreach (DB::select('SELECT state, count(*) AS n FROM batches GROUP BY state') as $r) {
            $batches[$r->state] = (int) $r->n;
        }
        $outbox = array_fill_keys(['pending', 'sent', 'failed'], 0);
        foreach (DB::select('SELECT status, count(*) AS n FROM prize_outbox GROUP BY status') as $r) {
            $outbox[$r->status] = (int) $r->n;
        }
        $red = DB::selectOne("SELECT count(*) AS total, count(*) FILTER (WHERE created_at >= now() - interval '24 hours') AS last24h FROM redemptions");
        $codes = DB::selectOne("SELECT count(*) AS total, count(*) FILTER (WHERE redeemed_at IS NOT NULL) AS redeemed FROM codes c JOIN batches b ON b.id = c.batch_id WHERE b.state = 'active'");
        $oldest = DB::selectOne("SELECT min(created_at) AS t FROM prize_outbox WHERE status = 'pending'");
        $approvals = DB::selectOne("SELECT count(*) FILTER (WHERE status = 'pending') AS pending, count(*) FILTER (WHERE status = 'approved') AS approved FROM approvals WHERE expires_at > now() AND status IN ('pending', 'approved')");

        return [
            'campaigns' => (int) DB::selectOne('SELECT count(*) AS n FROM campaigns')->n,
            'batches' => $batches,
            'activeCodes' => ['total' => (int) $codes->total, 'redeemed' => (int) $codes->redeemed],
            'redemptions' => ['total' => (int) $red->total, 'last24h' => (int) $red->last24h],
            'outbox' => $outbox + ['oldestPendingAt' => $oldest->t],
            'approvals' => ['pending' => (int) $approvals->pending, 'awaitingExecution' => (int) $approvals->approved],
            'admins' => (int) DB::selectOne('SELECT count(*) AS n FROM admin_users WHERE active')->n,
        ];
    }
}
