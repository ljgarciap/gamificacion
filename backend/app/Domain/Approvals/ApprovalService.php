<?php

namespace App\Domain\Approvals;

use App\Domain\Admin\AdminAccounts;
use App\Domain\Admin\AdminPermissions;
use App\Domain\Admin\LoginFailed;
use App\Domain\Audit\AuditLog;
use App\Domain\Batches\BatchLifecycle;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Control dual (SPEC-003 §8). Solicitar → aprobar (OTRO usuario, con un código TOTP nuevo) → ejecutar UNA vez (el solicitante). Lo que se ejecuta
 * son SIEMPRE los `params` guardados al solicitar (inmutables por trigger), nunca los que mande el cliente después. La caducidad se comprueba al
 * aprobar y al ejecutar, con el reloj de la APLICACIÓN. No existe la autoaprobación: la base la rechaza (CHECK), tampoco en local/testing.
 */
final class ApprovalService
{
    /** Acción → permiso que debe tener QUIEN solicita, aprueba o ejecuta, y tipo de objeto. */
    private const ACTIONS = [
        'batch.activate' => ['permission' => AdminPermissions::BATCHES_MANAGE, 'subject' => 'batch'],
        'setting.change' => ['permission' => AdminPermissions::SETTINGS_MANAGE, 'subject' => 'setting'],
    ];

    public function __construct(
        private readonly AuditLog $audit,
        private readonly AdminAccounts $accounts,
        private readonly BatchLifecycle $batches,
        private readonly Settings $settings,
    ) {}

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::ACTIONS);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return int id de la solicitud
     */
    public function request(object $user, string $action, array $params): int
    {
        $def = self::ACTIONS[$action] ?? throw new ApprovalException('validationFailed', 'Acción desconocida.', 422);
        $this->requirePermission($user, $def['permission']);
        [$subjectId, $params] = $this->validateParams($action, $params);

        $now = Carbon::now();
        $expires = $now->copy()->addHours(Settings::int('admin.approval_ttl_hours'));

        return DB::transaction(function () use ($user, $action, $def, $subjectId, $params, $now, $expires): int {
            // Una solicitud vencida no debe bloquear una nueva para el mismo objeto.
            DB::update(
                "UPDATE approvals SET status = 'expired' WHERE action = ? AND subject_type = ? AND subject_id = ? AND status IN ('pending', 'approved') AND expires_at < ?",
                [$action, $def['subject'], $subjectId, $now->format('Y-m-d H:i:s.uP')],
            );
            if (DB::selectOne("SELECT 1 AS x FROM approvals WHERE action = ? AND subject_type = ? AND subject_id = ? AND status IN ('pending', 'approved')", [$action, $def['subject'], $subjectId]) !== null) {
                throw new ApprovalException('alreadyPending', 'Ya hay una solicitud abierta para este objeto.', 409);
            }
            $row = DB::selectOne(
                'INSERT INTO approvals (action, subject_type, subject_id, params, requested_by, requested_at, expires_at)
                 VALUES (?, ?, ?, ?::jsonb, ?, ?, ?) RETURNING id',
                [$action, $def['subject'], $subjectId, json_encode($params), $user->id, $now->format('Y-m-d H:i:s.uP'), $expires->format('Y-m-d H:i:s.uP')],
            );
            $this->audit->append('admin:'.$user->id, 'approval.requested', 'approval', (string) $row->id, ['action' => $action, 'subject' => $subjectId]);

            return (int) $row->id;
        });
    }

    /** @throws ApprovalException */
    public function approve(object $user, int $id, string $code, ?string $ip): void
    {
        // Comprobaciones baratas ANTES de gastar el código TOTP (un paso solo sirve una vez).
        $this->checkApprovable($user, $this->find($id));

        try {
            $this->accounts->verifyMfa($user, $code, Carbon::now()->getTimestamp(), $ip);
        } catch (LoginFailed) {
            throw new ApprovalException('invalidCode', 'El código no es válido.', 422);
        }

        DB::transaction(function () use ($user, $id): void {
            $row = $this->lock($id);
            $this->checkApprovable($user, $row);
            DB::update(
                "UPDATE approvals SET status = 'approved', approved_by = ?, approved_at = ? WHERE id = ?",
                [$user->id, Carbon::now()->format('Y-m-d H:i:s.uP'), $id],
            );
            $this->audit->append('admin:'.$user->id, 'approval.approved', 'approval', (string) $id, [
                'action' => $row->action, 'subject' => $row->subject_id, 'requested_by' => (int) $row->requested_by,
            ]);
        });
    }

    /**
     * Ejecuta lo aprobado, una sola vez. Si la ejecución falla (p. ej. la baliza aún no publica la ronda) la transacción se revierte y la
     * aprobación sigue vigente para reintentar.
     *
     * @throws ApprovalException
     */
    public function execute(object $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            $row = $this->lock($id);
            if ((int) $row->requested_by !== (int) $user->id) {
                throw new ApprovalException('notRequester', 'Solo quien la solicitó puede ejecutarla.', 403);
            }
            $this->requirePermission($user, self::ACTIONS[$row->action]['permission']);
            if ($row->status !== 'approved') {
                throw new ApprovalException('wrongState', $row->status === 'executed' ? 'Ya se ejecutó.' : 'Aún no está aprobada.', 409);
            }
            $this->requireNotExpired($row);

            $params = json_decode($row->params, true);
            $actor = 'admin:'.$user->id;
            match ($row->action) {
                'batch.activate' => $this->batches->activate((string) $params['batchId'], $actor),
                'setting.change' => $this->settings->set((string) $params['key'], $params['value'], $actor),
            };

            DB::update("UPDATE approvals SET status = 'executed', executed_at = ? WHERE id = ?", [Carbon::now()->format('Y-m-d H:i:s.uP'), $id]);
            $this->audit->append($actor, 'approval.executed', 'approval', (string) $id, [
                'action' => $row->action, 'subject' => $row->subject_id, 'requested_by' => (int) $row->requested_by, 'approved_by' => (int) $row->approved_by,
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 200): array
    {
        return array_map(fn (object $r): array => [
            'id' => (int) $r->id, 'action' => $r->action, 'subjectType' => $r->subject_type, 'subjectId' => $r->subject_id,
            'params' => json_decode($r->params), 'status' => $this->effectiveStatus($r),
            'requestedBy' => ['id' => (int) $r->requested_by, 'name' => $r->requester_name],
            'approvedBy' => $r->approved_by === null ? null : ['id' => (int) $r->approved_by, 'name' => $r->approver_name],
            'requestedAt' => $r->requested_at, 'expiresAt' => $r->expires_at, 'approvedAt' => $r->approved_at, 'executedAt' => $r->executed_at,
        ], DB::select(
            'SELECT a.id, a.action, a.subject_type, a.subject_id, a.params::text AS params, a.status, a.requested_by, a.requested_at, a.expires_at,
                    a.approved_by, a.approved_at, a.executed_at, r.name AS requester_name, p.name AS approver_name
             FROM approvals a JOIN admin_users r ON r.id = a.requested_by LEFT JOIN admin_users p ON p.id = a.approved_by
             ORDER BY a.id DESC LIMIT ?', [$limit]));
    }

    /** RF-45: con menos de dos personas que puedan aprobar, el control dual no es real y el panel lo advierte. */
    public function fewApprovers(): bool
    {
        $roles = array_values(array_filter(AdminPermissions::ROLES, fn (string $r): bool => AdminPermissions::allows($r, AdminPermissions::APPROVALS_APPROVE)));

        return (int) DB::selectOne('SELECT count(*) AS n FROM admin_users WHERE active AND role = ANY(?::text[])', ['{'.implode(',', $roles).'}'])->n < 2;
    }

    // --- Internos --------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $params @return array{0: string, 1: array<string, mixed>} */
    private function validateParams(string $action, array $params): array
    {
        if ($action === 'batch.activate') {
            $batchId = $params['batchId'] ?? null;
            if (! is_string($batchId) || ! preg_match('/^[0-9a-f-]{36}$/', $batchId)) {
                throw new ApprovalException('validationFailed', 'Indica el lote («batchId»).', 422);
            }
            $state = DB::table('batches')->where('id', $batchId)->value('state');
            if ($state === null) {
                throw new ApprovalException('notFound', 'El lote no existe.', 404);
            }
            if ($state !== 'committed') {
                throw new ApprovalException('validationFailed', 'Solo se puede activar un lote comprometido.', 422);
            }

            return [$batchId, ['batchId' => $batchId]];
        }

        // setting.change: se valida AL SOLICITAR (tipo, rango, coherencia) para no aprobar algo que luego no se pueda ejecutar.
        $key = $params['key'] ?? null;
        $def = is_string($key) ? SettingsRegistry::get($key) : null;
        if ($def === null) {
            throw new ApprovalException('validationFailed', 'Parámetro desconocido.', 422);
        }
        if (! $def['security']) {
            throw new ApprovalException('validationFailed', 'Este parámetro no necesita aprobación: se cambia directamente.', 422);
        }
        try {
            $this->settings->validate($key, $params['value'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new ApprovalException('validationFailed', $e->getMessage(), 422);
        }

        return [$key, ['key' => $key, 'value' => $params['value']]];
    }

    private function checkApprovable(object $user, object $row): void
    {
        $this->requirePermission($user, self::ACTIONS[$row->action]['permission']);
        if ((int) $row->requested_by === (int) $user->id) {
            throw new ApprovalException('selfApproval', 'No puedes aprobar tu propia solicitud.', 403);
        }
        if ($row->status !== 'pending') {
            throw new ApprovalException('wrongState', 'La solicitud ya no está pendiente.', 409);
        }
        $this->requireNotExpired($row);
    }

    private function requireNotExpired(object $row): void
    {
        if (Carbon::parse($row->expires_at)->lte(Carbon::now())) {
            throw new ApprovalException('expired', 'La solicitud caducó.', 409);
        }
    }

    private function requirePermission(object $user, string $permission): void
    {
        if (! AdminPermissions::allows($user->role, $permission)) {
            throw new ApprovalException('forbidden', 'No tienes permiso para esta acción.', 403);
        }
    }

    private function find(int $id): object
    {
        return DB::selectOne('SELECT *, params::text AS params FROM approvals WHERE id = ?', [$id]) ?? throw new ApprovalException('notFound', 'La solicitud no existe.', 404);
    }

    private function lock(int $id): object
    {
        return DB::selectOne('SELECT *, params::text AS params FROM approvals WHERE id = ? FOR UPDATE', [$id]) ?? throw new ApprovalException('notFound', 'La solicitud no existe.', 404);
    }

    private function effectiveStatus(object $r): string
    {
        $open = in_array($r->status, ['pending', 'approved'], true);

        return $open && Carbon::parse($r->expires_at)->lte(Carbon::now()) ? 'expired' : $r->status;
    }
}
