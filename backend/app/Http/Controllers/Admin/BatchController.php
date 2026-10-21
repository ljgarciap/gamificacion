<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminException;
use App\Domain\Batches\BatchAdminService;
use App\Domain\Beacon\BeaconUnavailable;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Lotes (SPEC-003 RF-36/37). Activar NO está aquí: es una acción de control dual (`/approvals`, `batch.activate`). */
final class BatchController
{
    public function __construct(private readonly BatchAdminService $batches) {}

    public function index(Request $request): JsonResponse
    {
        return $this->guard(fn () => response()->json(
            ['batches' => $this->batches->list($this->str($request, 'campaignId'), $this->str($request, 'state'))], 200, ['Cache-Control' => 'no-store'],
        ));
    }

    public function show(string $id): JsonResponse
    {
        return $this->guard(fn () => response()->json($this->batches->get($id), 200, ['Cache-Control' => 'no-store']));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guard(fn () => response()->json(['id' => $this->batches->create($request->json()->all(), $this->actor($request))], 201));
    }

    public function commit(Request $request, string $id): JsonResponse
    {
        return $this->guard(fn () => response()->json($this->batches->commit($id, $this->actor($request))));
    }

    public function close(Request $request, string $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $this->batches->close($id, $this->actor($request));

            return response()->noContent();
        });
    }

    public function reveal(Request $request, string $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $this->batches->reveal($id, $this->actor($request));

            return response()->noContent();
        });
    }

    public function redemptions(Request $request, string $id): JsonResponse
    {
        return $this->guard(fn () => response()->json(
            $this->batches->redemptions($id, (int) $request->query('page', 1), (int) $request->query('perPage', 50)), 200, ['Cache-Control' => 'no-store'],
        ));
    }

    public function lookup(Request $request): JsonResponse
    {
        $code = $request->json('code');
        if (! is_string($code) || strlen($code) > 64) {
            return ApiError::response('validationFailed', 'Indica el «code» a buscar.', 422);
        }

        return $this->guard(fn () => response()->json($this->batches->lookupCode($code, $this->actor($request)), 200, ['Cache-Control' => 'no-store']));
    }

    private function guard(callable $run): JsonResponse|Response
    {
        try {
            return $run();
        } catch (AdminException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        } catch (BeaconUnavailable) {
            return ApiError::response('beaconUnavailable', 'La baliza no responde; reintenta en unos segundos.', 503);
        }
    }

    private function str(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function actor(Request $request): string
    {
        return 'admin:'.$request->attributes->get('admin_user')->id;
    }
}
