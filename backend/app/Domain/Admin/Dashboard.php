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
        // Sin recorrer `codes`: los totales vienen del pool y los canjes de `redemptions` (índice por lote).
        $codes = DB::selectOne("SELECT coalesce(sum(b.total_codes), 0) AS total, (SELECT count(*) FROM redemptions r JOIN batches x ON x.id = r.batch_id WHERE x.state = 'active' AND r.code_id IS NOT NULL) AS redeemed FROM batches b WHERE b.state = 'active'");
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
            'stockByBatch' => $this->stockByBatch(),
            'redemptionsByDay' => $this->redemptionsByDay(),
        ];
    }

    /**
     * Posiciones del mazo sin entregar por premio en cada lote ACTIVO (pool − canjes de código − giros jugados). Es lo que el detalle del lote ya
     * muestra por separado; no revela qué código tiene qué premio. Con reserva, las casillas sin consumir cuentan como restantes.
     *
     * @return list<array{batchId: string, name: string, prizes: list<array{prizeId: string, total: int, delivered: int, remaining: int}>}>
     */
    private function stockByBatch(): array
    {
        $out = [];
        foreach (DB::select("SELECT id, name, pool::text AS pool FROM batches WHERE state = 'active' ORDER BY activated_at, id") as $b) {
            $delivered = [];
            foreach (DB::select('SELECT prize_id, count(*) AS n FROM redemptions WHERE batch_id = ? GROUP BY prize_id', [$b->id]) as $r) {
                $delivered[$r->prize_id] = (int) $r->n;
            }
            $prizes = [];
            foreach (json_decode($b->pool, true) as $e) {
                $total = (int) $e['quantity'];
                $done = $delivered[$e['prize_id']] ?? 0;
                $prizes[] = ['prizeId' => $e['prize_id'], 'total' => $total, 'delivered' => $done, 'remaining' => max(0, $total - $done)];
            }
            $out[] = ['batchId' => $b->id, 'name' => $b->name, 'prizes' => $prizes];
        }

        return $out;
    }

    /**
     * Canjes (códigos y giros) de los últimos 14 días, por día de Bogotá, con ceros en los días sin canjes.
     *
     * @return list<array{day: string, count: int}>
     */
    private function redemptionsByDay(): array
    {
        $rows = DB::select("SELECT d::date AS day, count(r.id) AS n
            FROM generate_series((now() AT TIME ZONE 'America/Bogota')::date - 13, (now() AT TIME ZONE 'America/Bogota')::date, interval '1 day') d
            LEFT JOIN redemptions r ON r.created_at >= now() - interval '15 days' AND (r.created_at AT TIME ZONE 'America/Bogota')::date = d::date
            GROUP BY d ORDER BY d");

        return array_map(static fn (object $r): array => ['day' => $r->day, 'count' => (int) $r->n], $rows);
    }
}
