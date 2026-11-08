<?php

namespace App\Domain\Demo;

use App\Domain\Admin\AdminException;
use App\Domain\Audit\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Corridas de sembrado/reinicio (SPEC-006 M2). Una sola activa a la vez (índice único parcial en la base). El panel solo PIDE la corrida; la
 * ejecuta el servicio `worker` (`gamification:demo:process`, cada minuto) porque esperar a la baliza real tarda y no cabe en una petición web.
 */
final class DemoRuns
{
    /** Una corrida «running» que lleva más que esto se da por muerta (el worker cayó) para no bloquear el panel para siempre. */
    public const STALE_MINUTES = 30;

    public function __construct(private readonly AuditLog $audit = new AuditLog) {}

    public function request(string $kind, string $actor): int
    {
        try {
            return DB::transaction(function () use ($kind, $actor): int {
                $id = (int) DB::selectOne('INSERT INTO demo_runs (kind, requested_by) VALUES (?, ?) RETURNING id', [$kind, $actor])->id;
                $this->audit->append($actor, 'demo.run_requested', 'demo', (string) $id, ['kind' => $kind]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new AdminException('alreadyPending', 'Ya hay una corrida de la muestra en curso; espera a que termine.', 409);
        }
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $r = DB::selectOne('SELECT id, kind, state, error, requested_by, created_at, started_at, finished_at FROM demo_runs WHERE id = ?', [$id]);

        return $r === null ? null : self::present($r);
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        $r = DB::selectOne('SELECT id, kind, state, error, requested_by, created_at, started_at, finished_at FROM demo_runs ORDER BY id DESC LIMIT 1');

        return $r === null ? null : self::present($r);
    }

    /** Marca como fallidas las corridas «running» abandonadas. @return int cuántas */
    public function failStale(): int
    {
        return DB::update(
            "UPDATE demo_runs SET state = 'failed', error = 'El proceso se interrumpió (no terminó a tiempo).', finished_at = now()
             WHERE state = 'running' AND started_at < now() - make_interval(mins => ?)",
            [self::STALE_MINUTES],
        );
    }

    /** Toma la corrida pendiente más antigua (una sola) y la pone en marcha. @return array{id: int, kind: string, requested_by: string}|null */
    public function claim(): ?array
    {
        $r = DB::selectOne(
            "UPDATE demo_runs SET state = 'running', started_at = now()
             WHERE id = (SELECT id FROM demo_runs WHERE state = 'pending' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED)
             RETURNING id, kind, requested_by",
        );

        return $r === null ? null : ['id' => (int) $r->id, 'kind' => $r->kind, 'requested_by' => $r->requested_by];
    }

    public function finish(int $id, ?string $error = null): void
    {
        DB::update("UPDATE demo_runs SET state = ?, error = ?, finished_at = now() WHERE id = ?", [$error === null ? 'done' : 'failed', $error === null ? null : mb_substr($error, 0, 500), $id]);
    }

    /** @return array<string, mixed> */
    private static function present(object $r): array
    {
        $iso = static fn (?string $t): ?string => $t === null ? null : \Illuminate\Support\Carbon::parse($t)->toIso8601String();

        return [
            'id' => (int) $r->id, 'kind' => $r->kind, 'state' => $r->state, 'error' => $r->error, 'requestedBy' => $r->requested_by,
            'createdAt' => $iso($r->created_at), 'startedAt' => $iso($r->started_at), 'finishedAt' => $iso($r->finished_at),
        ];
    }
}
