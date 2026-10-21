<?php

namespace App\Domain\Fulfillment;

use App\Domain\Admin\AdminException;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Vista y reintento de la outbox desde el panel (SPEC-003 RF-39). Solo se reencolan avisos `failed` (los agotados); uno `sent` no se toca
 * (la base lo prohíbe) y uno `pending` ya está en cola. El reintento puede entregar un aviso que el host ya recibió pero no confirmó: el
 * aviso lleva `redemption_id`, que es la clave de idempotencia del lado del host.
 */
final class OutboxAdmin
{
    private const STATUSES = ['pending', 'sent', 'failed'];

    private const MAX_PAGE = 200;

    public function __construct(private readonly AuditLog $audit) {}

    /** @return array{items: list<array<string, mixed>>, total: int, counts: array<string, int>, page: int, perPage: int} */
    public function list(?string $status, int $page, int $perPage): array
    {
        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            throw new AdminException('validationFailed', 'Estado desconocido.', 422);
        }
        $page = max(1, $page);
        $perPage = min(self::MAX_PAGE, max(1, $perPage));
        $rows = DB::select(
            "SELECT id, redemption_id, status, attempts, next_attempt_at, last_error, created_at, sent_at,
                    payload->>'user_id' AS user_id, payload->>'batch_id' AS batch_id, payload->'prize'->>'id' AS prize_id, payload->'prize'->>'type' AS prize_type
             FROM prize_outbox WHERE (?::text IS NULL OR status = ?) ORDER BY id DESC LIMIT ? OFFSET ?",
            [$status, $status, $perPage, ($page - 1) * $perPage],
        );
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (DB::select('SELECT status, count(*) AS n FROM prize_outbox GROUP BY status') as $r) {
            $counts[$r->status] = (int) $r->n;
        }

        return [
            'items' => array_map(static fn (object $r): array => [
                'id' => (int) $r->id, 'redemptionId' => $r->redemption_id, 'status' => $r->status, 'attempts' => (int) $r->attempts,
                'nextAttemptAt' => $r->next_attempt_at, 'lastError' => $r->last_error, 'createdAt' => $r->created_at, 'sentAt' => $r->sent_at,
                'userId' => $r->user_id, 'batchId' => $r->batch_id, 'prizeId' => $r->prize_id, 'prizeType' => $r->prize_type,
            ], $rows),
            'total' => $status === null ? array_sum($counts) : $counts[$status],
            'counts' => $counts, 'page' => $page, 'perPage' => $perPage,
        ];
    }

    /** Devuelve un aviso agotado a la cola con los intentos en cero. */
    public function retry(int $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $row = DB::selectOne('SELECT id, status, attempts FROM prize_outbox WHERE id = ? FOR UPDATE', [$id])
                ?? throw new AdminException('notFound', 'El aviso no existe.', 404);
            if ($row->status !== 'failed') {
                throw new AdminException('wrongState', "Solo se reintenta un aviso agotado (este está «{$row->status}»).", 409);
            }
            DB::update("UPDATE prize_outbox SET status = 'pending', attempts = 0, next_attempt_at = now() WHERE id = ?", [$id]);
            $this->audit->append($actor, 'outbox.retried', 'outbox', (string) $id, ['previous_attempts' => (int) $row->attempts]);
        });
    }
}
