<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminException;
use App\Domain\Audit\AuditAnchor;
use App\Domain\Audit\AuditLog;
use App\Domain\Audit\AuditViewer;
use App\Domain\Settings\Settings;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Visor de la bitácora del panel (SPEC-003 RF-38): solo lectura. La exportación de un lote es la acción dual `audit.export`. */
final class AuditController
{
    public function __construct(private readonly AuditViewer $viewer, private readonly AuditLog $log, private readonly AuditAnchor $anchor) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [];
            foreach (['action', 'subjectType', 'subjectId', 'actor', 'from', 'to'] as $key) {
                $v = $request->query($key);
                $filters[$key] = is_string($v) && $v !== '' ? $v : null;
            }

            return response()->json(
                $this->viewer->list($filters, $this->int($request, 'beforeId', 1), $this->int($request, 'perPage', 1) ?? 50),
                200, ['Cache-Control' => 'no-store'],
            );
        } catch (AdminException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        }
    }

    /**
     * Verifica un tramo de la cadena. El cliente la recorre de `afterId = 0` hasta `done` (cada respuesta trae el `lastId` siguiente). Recalcula
     * hashes: cuesta CPU, por eso se limita por usuario.
     */
    public function verify(Request $request): JsonResponse
    {
        try {
            $afterId = $this->int($request, 'afterId', 0) ?? 0;
            $limit = min(AuditViewer::MAX_VERIFY_SEGMENT, $this->int($request, 'limit', 1) ?? 1000);
        } catch (AdminException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        }

        $key = 'gamif:admin:audit-verify:'.$request->attributes->get('admin_user')->id;
        if (RateLimiter::tooManyAttempts($key, Settings::int('admin.audit_verify_per_minute'))) {
            return ApiError::response('rateLimited', 'Demasiadas verificaciones; espera un momento.', 429, RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        try {
            return response()->json($this->log->verifySegment($afterId, $limit), 200, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException) {
            return ApiError::response('validationFailed', '«afterId» no existe en la bitácora.', 422);
        }
    }

    /** Último hash de la bitácora, para publicarlo FUERA de la base (defensa contra el recorte de la cola). */
    public function anchor(): JsonResponse
    {
        return response()->json(['anchor' => $this->anchor->current()], 200, ['Cache-Control' => 'no-store']);
    }

    private function int(Request $request, string $key, int $min): ?int
    {
        $v = $request->query($key);
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_string($v) || preg_match('/^\d{1,18}$/', $v) !== 1 || (int) $v < $min) {
            throw new AdminException('validationFailed', "«{$key}» debe ser un entero válido.", 422);
        }

        return (int) $v;
    }
}
