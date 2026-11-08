<?php

namespace App\Http\Controllers\Demo;

use App\Domain\Auth\HostUser;
use App\Domain\Demo\DemoShop;
use App\Domain\Demo\DemoShopException;
use App\Domain\Demo\DemoSwitch;
use App\Domain\Dev\DevIssuerNotConfigured;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Superficie pública de la muestra (SPEC-006): estado, sesión simulada y compra. Solo responden con la muestra encendida (salvo `status`). */
final class DemoPublicController
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._\-:]{8,128}$/';

    private const NO_STORE = ['Cache-Control' => 'no-store'];

    /** GET /v1/demo/status → {"enabled": bool}. Siempre 200: la app decide con él si muestra el recorrido de la muestra o el flujo normal. */
    public function status(DemoSwitch $switch): JsonResponse
    {
        return response()->json(['enabled' => $switch->isActive()], 200, self::NO_STORE);
    }

    /** POST /v1/demo/session {"resume"?: "<subject>"} → token + catálogo + reglas + saldo. */
    public function session(Request $request, DemoShop $shop): JsonResponse
    {
        $resume = $request->input('resume');
        try {
            return response()->json($shop->openSession(is_string($resume) ? $resume : null, $request->ip()), 200, self::NO_STORE);
        } catch (DemoShopException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        } catch (DevIssuerNotConfigured $e) {
            return ApiError::response('devIssuerNotConfigured', $e->getMessage(), 503);
        }
    }

    /** POST /v1/demo/purchase {"items":[{"productId","quantity"}]}  Idempotency-Key. */
    public function purchase(Request $request, DemoShop $shop): JsonResponse
    {
        /** @var HostUser $user */
        $user = $request->attributes->get('gamification_user');
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return ApiError::response('idempotencyKeyRequired', 'Falta el encabezado Idempotency-Key (8 a 128 caracteres: letras, números y ._-:).', 400);
        }
        try {
            return response()->json($shop->purchase($user->id(), $key, $request->input('items')), 200, self::NO_STORE);
        } catch (DemoShopException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        }
    }
}
