<?php

namespace App\Domain\Approvals;

use App\Domain\Admin\AdminAccounts;
use App\Domain\Admin\AdminPermissions;
use App\Domain\Admin\LoginFailed;
use App\Domain\Audit\AuditAnchor;
use App\Domain\Audit\AuditExporter;
use App\Domain\Audit\AuditLog;
use App\Domain\Batches\BatchLifecycle;
use App\Domain\Batches\InvalidBatch;
use App\Domain\Codes\CodeExportStore;
use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\CodeGenerator;
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
        'codes.generate' => ['permission' => AdminPermissions::BATCHES_MANAGE, 'subject' => 'batch'],
        'audit.export' => ['permission' => AdminPermissions::AUDIT_EXPORT, 'subject' => 'batch'],
        'setting.change' => ['permission' => AdminPermissions::SETTINGS_MANAGE, 'subject' => 'setting'],
    ];

    public function __construct(
        private readonly AuditLog $audit,
        private readonly AdminAccounts $accounts,
        private readonly BatchLifecycle $batches,
        private readonly Settings $settings,
        private readonly CodeGenerator $generator,
        private readonly CodeExportStore $exports,
        private readonly AuditExporter $auditExporter,
        private readonly AuditAnchor $anchor,
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
     * @return array<string, mixed>|null resultado que el solicitante necesita (generación de códigos: huella y plazo de descarga)
     *
     * @throws ApprovalException
     */
    public function execute(object $user, int $id): ?array
    {
        $path = null;
        try {
            return DB::transaction(function () use ($user, $id, &$path): ?array {
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
            $result = null;
            match ($row->action) {
                'batch.activate' => $this->batches->activate((string) $params['batchId'], $actor),
                'setting.change' => $this->settings->set((string) $params['key'], $params['value'], $actor),
                'codes.generate' => $result = $this->generateCodes($id, $params, $actor, $path),
                'audit.export' => $result = $this->exportAudit($id, $params, $actor, $path),
            };

            DB::update("UPDATE approvals SET status = 'executed', executed_at = ? WHERE id = ?", [Carbon::now()->format('Y-m-d H:i:s.uP'), $id]);
            $this->audit->append($actor, 'approval.executed', 'approval', (string) $id, [
                'action' => $row->action, 'subject' => $row->subject_id, 'requested_by' => (int) $row->requested_by, 'approved_by' => (int) $row->approved_by,
            ]);

            return $result;
            });
        } catch (\Throwable $e) {
            // Si la transacción se revierte, los códigos desaparecen de la base: un archivo con códigos que ya no existen no debe quedar.
            if ($path !== null) {
                $this->exports->discard($path);
            }
            throw $e;
        }
    }

    /**
     * Entrega ÚNICA de los códigos generados: solo el solicitante, solo mientras no caduque, y el archivo se reclama de forma atómica (renombrado),
     * así que dos descargas simultáneas no pueden ganar las dos. Se comprueba que el archivo sigue siendo el que se registró en la bitácora.
     *
     * @return array{path: string, sha256: string, name: string, type: string}
     *
     * @throws ApprovalException
     */
    public function claimExport(object $user, int $id): array
    {
        $row = $this->find($id);
        if (! in_array($row->action, ['codes.generate', 'audit.export'], true) || (int) $row->requested_by !== (int) $user->id) {
            throw new ApprovalException('notFound', 'No hay descarga para esta solicitud.', 404);
        }
        $this->requirePermission($user, self::ACTIONS[$row->action]['permission']);
        $this->exports->purgeExpired();
        $until = Carbon::parse($row->executed_at ?? '1970-01-01')->addMinutes(Settings::int('admin.export_ttl_minutes'));
        if ($row->status !== 'executed' || $until->lte(Carbon::now())) {
            throw new ApprovalException('gone', 'La descarga ya no está disponible. Si se perdió, hay que recrear el lote.', 410);
        }
        $kind = $row->action === 'audit.export' ? 'audit' : 'codes';
        $path = $this->exports->claim($id, $kind) ?? throw new ApprovalException('gone', 'La descarga ya no está disponible. Si se perdió, hay que recrear el lote.', 410);

        $params = json_decode($row->params, true);
        $logged = $kind === 'audit' ? 'audit.exported' : 'batch.codes_generated';
        $expected = DB::selectOne("SELECT data->>'export_sha256' AS sha FROM audit_log WHERE action = ? AND subject_id = ? ORDER BY id DESC LIMIT 1", [$logged, $params['batchId']])->sha ?? null;
        $actual = hash_file('sha256', $path);
        if ($expected === null || ! hash_equals($expected, $actual)) {
            throw new ApprovalException('exportTampered', 'El archivo no coincide con el registrado en la bitácora; no se entrega.', 500);
        }
        $this->audit->append('admin:'.$user->id, $kind === 'audit' ? 'audit.downloaded' : 'codes.downloaded', 'approval', (string) $id, ['batch' => $params['batchId'], 'export_sha256' => $actual]);

        return [
            'path' => $path, 'sha256' => $actual,
            'name' => $kind === 'audit' ? "auditoria-$id.json" : "codigos-$id.csv",
            'type' => $kind === 'audit' ? 'application/json' : 'text/csv; charset=utf-8',
        ];
    }

    /**
     * Escribe la exportación de auditoría (JSON, 0600) y el ancla actual. La exportación no lleva secretos pendientes (el lote ya está revelado),
     * pero sí toda la bitácora: por eso se pide control dual y se entrega una sola vez, como los códigos.
     *
     * @param array<string, mixed> $params @return array<string, mixed>
     */
    private function exportAudit(int $approvalId, array $params, string $actor, ?string &$path): array
    {
        $this->exports->purgeExpired();
        try {
            $export = $this->auditExporter->export((string) $params['batchId']);
        } catch (InvalidBatch $e) {
            throw new ApprovalException('validationFailed', $e->getMessage(), 422);
        }
        $path = $this->exports->path($approvalId, 'audit');
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            $path = null;
            throw new ApprovalException('wrongState', 'Ya existe una exportación pendiente para esta solicitud.', 409);
        }
        chmod($path, 0600);
        fwrite($fh, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        fclose($fh);
        $sha = hash_file('sha256', $path);
        $this->audit->append($actor, 'audit.exported', 'batch', (string) $params['batchId'], ['export_sha256' => $sha, 'entries' => count($export['audit_log'])]);

        return [
            'sha256' => $sha, 'entries' => count($export['audit_log']), 'anchor' => $this->anchor->current(),
            'downloadUntil' => Carbon::now()->addMinutes(Settings::int('admin.export_ttl_minutes'))->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function generateCodes(int $approvalId, array $params, string $actor, ?string &$path): array
    {
        $this->exports->purgeExpired();
        $path = $this->exports->path($approvalId);
        try {
            $g = $this->generator->generate((string) $params['batchId'], (string) $params['prefix'], $path, $actor);
        } catch (InvalidBatch $e) {
            $path = null; // nada se escribió
            throw new ApprovalException('validationFailed', $e->getMessage(), 422);
        }

        return [
            'count' => $g->count, 'sha256' => $g->sha256,
            'downloadUntil' => Carbon::now()->addMinutes(Settings::int('admin.export_ttl_minutes'))->toIso8601String(),
        ];
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

        if ($action === 'audit.export') {
            $batchId = $params['batchId'] ?? null;
            if (! is_string($batchId) || ! preg_match('/^[0-9a-f-]{36}$/', $batchId)) {
                throw new ApprovalException('validationFailed', 'Indica el lote («batchId»).', 422);
            }
            $state = DB::table('batches')->where('id', $batchId)->value('state');
            if ($state === null) {
                throw new ApprovalException('notFound', 'El lote no existe.', 404);
            }
            if ($state !== 'revealed') {
                throw new ApprovalException('validationFailed', 'Solo se exporta la auditoría de un lote revelado.', 422);
            }

            return [$batchId, ['batchId' => $batchId]];
        }

        if ($action === 'codes.generate') {
            $batchId = $params['batchId'] ?? null;
            $prefix = $params['prefix'] ?? null;
            if (! is_string($batchId) || ! preg_match('/^[0-9a-f-]{36}$/', $batchId) || ! is_string($prefix) || ! CodeFormat::isValidPrefix($prefix)) {
                throw new ApprovalException('validationFailed', 'Indica el lote («batchId») y un prefijo («prefix») de 2 a '.CodeFormat::MAX_PREFIX.' caracteres, mayúsculas y dígitos, empezando por letra.', 422);
            }
            $b = DB::table('batches')->where('id', $batchId)->first(['state']);
            if ($b === null) {
                throw new ApprovalException('notFound', 'El lote no existe.', 404);
            }
            if ($b->state !== 'draft' || DB::table('codes')->where('batch_id', $batchId)->exists()) {
                throw new ApprovalException('validationFailed', 'Solo se generan códigos para un borrador sin códigos.', 422);
            }

            return [$batchId, ['batchId' => $batchId, 'prefix' => $prefix]];
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
