<?php

namespace App\Domain\Audit;

use App\Domain\Admin\AdminException;
use Illuminate\Support\Facades\DB;

/** Lectura de la bitácora para el panel (SPEC-003 RF-38): solo consulta, paginación por clave (la tabla crece sin parar). */
final class AuditViewer
{
    public const MAX_PER_PAGE = 200;

    public const MAX_VERIFY_SEGMENT = 5000;

    /**
     * @param  array{action?: string|null, subjectType?: string|null, subjectId?: string|null, actor?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{items: list<array<string, mixed>>, nextBeforeId: int|null, perPage: int}
     */
    public function list(array $filters, ?int $beforeId, int $perPage): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $where = [];
        $args = [];
        foreach (['action' => 'action', 'subjectType' => 'subject_type', 'subjectId' => 'subject_id', 'actor' => 'actor'] as $key => $column) {
            $v = $filters[$key] ?? null;
            if ($v !== null) {
                if (strlen($v) > 190 || preg_match('/\p{Cc}/u', $v) === 1) {
                    throw new AdminException('validationFailed', "«{$key}» no es válido.", 422);
                }
                $where[] = "$column = ?";
                $args[] = $v;
            }
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $v = $filters[$key] ?? null;
            if ($v !== null) {
                try {
                    $at = new \DateTimeImmutable($v);
                } catch (\Throwable) {
                    throw new AdminException('validationFailed', "«{$key}» debe ser una fecha ISO 8601.", 422);
                }
                $where[] = "occurred_at $op ?";
                $args[] = $at->format('Y-m-d\TH:i:s.uP');
            }
        }
        if ($beforeId !== null) {
            $where[] = 'id < ?';
            $args[] = $beforeId;
        }
        $rows = DB::select(
            'SELECT id, occurred_at, actor, action, subject_type, subject_id, data::text AS data FROM audit_log'
            .($where === [] ? '' : ' WHERE '.implode(' AND ', $where)).' ORDER BY id DESC LIMIT ?',
            [...$args, $perPage + 1],
        );
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        return [
            'items' => array_map(static fn (object $r): array => [
                'id' => (int) $r->id, 'occurredAt' => $r->occurred_at, 'actor' => $r->actor, 'action' => $r->action,
                'subjectType' => $r->subject_type, 'subjectId' => $r->subject_id, 'data' => json_decode($r->data, true),
            ], $rows),
            'nextBeforeId' => $more ? (int) $rows[array_key_last($rows)]->id : null,
            'perPage' => $perPage,
        ];
    }
}
