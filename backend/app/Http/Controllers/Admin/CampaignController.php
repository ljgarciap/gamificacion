<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminException;
use App\Domain\Campaigns\CampaignService;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Campañas y premios (SPEC-003 RF-35). Lectura para todos los roles; escritura para superadmin y operador. */
final class CampaignController
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(): JsonResponse
    {
        return response()->json(['campaigns' => $this->campaigns->list()], 200, ['Cache-Control' => 'no-store']);
    }

    public function show(string $id): JsonResponse
    {
        return $this->guard(fn () => response()->json($this->campaigns->get($id), 200, ['Cache-Control' => 'no-store']));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guard(fn () => response()->json(['id' => $this->campaigns->create($request->json()->all(), $this->actor($request))], 201));
    }

    public function update(Request $request, string $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $this->campaigns->update($id, $request->json()->all(), $this->actor($request));

            return response()->noContent();
        });
    }

    public function addPrize(Request $request, string $id): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id): Response {
            $this->campaigns->addPrize($id, $request->json()->all(), $this->actor($request));

            return response()->noContent(201);
        });
    }

    public function updatePrize(Request $request, string $id, string $prizeId): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id, $prizeId): Response {
            $this->campaigns->updatePrize($id, $prizeId, $request->json()->all(), $this->actor($request));

            return response()->noContent();
        });
    }

    public function deletePrize(Request $request, string $id, string $prizeId): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $id, $prizeId): Response {
            $this->campaigns->deletePrize($id, $prizeId, $this->actor($request));

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
