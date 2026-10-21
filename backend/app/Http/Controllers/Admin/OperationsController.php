<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminException;
use App\Domain\Admin\Dashboard;
use App\Domain\Fulfillment\OutboxAdmin;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Resumen y outbox del panel (SPEC-003 RF-39 y RF-42). */
final class OperationsController
{
    public function __construct(private readonly Dashboard $dashboard, private readonly OutboxAdmin $outbox) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->dashboard->summary(), 200, ['Cache-Control' => 'no-store']);
    }

    public function outbox(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return $this->guard(fn () => response()->json(
            $this->outbox->list(is_string($status) && $status !== '' ? $status : null, (int) $request->query('page', 1), (int) $request->query('perPage', 50)),
            200, ['Cache-Control' => 'no-store'],
        ));
    }

    public function retry(Request $request, int $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $this->outbox->retry($id, 'admin:'.$request->attributes->get('admin_user')->id);

            return response()->noContent();
        });
    }

    private function guard(callable $run): JsonResponse|Response
    {
        try {
            return $run();
        } catch (AdminException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        }
    }
}
