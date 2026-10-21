<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminException;
use App\Domain\Hosts\HostService;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Apps host y sus secretos (SPEC-003 RF-49). Solo superadmin. El secreto sale una vez (al crear o rotar) y con `Cache-Control: no-store`. */
final class HostController
{
    public function __construct(private readonly HostService $hosts) {}

    public function index(): JsonResponse
    {
        return response()->json(['hosts' => $this->hosts->list()], 200, ['Cache-Control' => 'no-store']);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guard(fn () => response()->json($this->hosts->create($request->json()->all(), $this->actor($request)), 201, ['Cache-Control' => 'no-store']));
    }

    public function update(Request $request, int $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $this->hosts->update($id, $request->json()->all(), $this->actor($request));

            return response()->noContent();
        });
    }

    public function rotate(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => response()->json(['secret' => $this->hosts->rotate($id, $this->actor($request))], 200, ['Cache-Control' => 'no-store']));
    }

    public function retirePrevious(Request $request, int $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $this->hosts->retirePrevious($id, $this->actor($request));

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

    private function actor(Request $request): string
    {
        return 'admin:'.$request->attributes->get('admin_user')->id;
    }
}
