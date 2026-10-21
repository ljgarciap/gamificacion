<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Approvals\ApprovalException;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Batches\BeaconNotReady;
use App\Domain\Batches\InvalidTransition;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Control dual (SPEC-003 P3): solicitar, aprobar con un código TOTP nuevo y ejecutar una vez. */
final class ApprovalController
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(): JsonResponse
    {
        return response()->json(
            ['approvals' => $this->approvals->list(), 'fewApprovers' => $this->approvals->fewApprovers()],
            200, ['Cache-Control' => 'no-store'],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $action = $request->input('action');
        $params = $request->input('params');
        if (! is_string($action) || ! is_array($params)) {
            return ApiError::response('validationFailed', 'Indica «action» y «params».', 422);
        }

        return $this->guard(fn () => response()->json(
            ['id' => $this->approvals->request($request->attributes->get('admin_user'), $action, $params)], 201,
        ));
    }

    public function approve(Request $request, int $id): JsonResponse|Response
    {
        $code = $request->input('code');
        if (! is_string($code)) {
            return ApiError::response('validationFailed', 'Indica el código del segundo factor.', 422);
        }

        return $this->guard(function () use ($request, $id): Response {
            $this->approvals->approve($request->attributes->get('admin_user'), $id, (string) $request->input('code'), $request->ip());

            return response()->noContent();
        });
    }

    public function execute(Request $request, int $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $result = $this->approvals->execute($request->attributes->get('admin_user'), $id);

            return $result === null ? response()->noContent() : response()->json($result, 200, ['Cache-Control' => 'no-store']);
        });
    }

    /** Entrega única del CSV de `codes.generate`. El archivo se destruye al terminar de enviarse. */
    public function download(Request $request, int $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $c = $this->approvals->claimCodes($request->attributes->get('admin_user'), $id);

            return response()->download($c['path'], "codigos-$id.csv", [
                'Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-SHA256' => $c['sha256'],
            ])->deleteFileAfterSend(true);
        });
    }

    private function guard(callable $run): JsonResponse|Response
    {
        try {
            return $run();
        } catch (ApprovalException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        } catch (BeaconNotReady) {
            return ApiError::response('beaconNotReady', 'La baliza aún no publica la ronda comprometida; reintenta en unos segundos.', 409);
        } catch (InvalidTransition $e) {
            return ApiError::response('wrongState', $e->getMessage(), 409);
        }
    }
}
