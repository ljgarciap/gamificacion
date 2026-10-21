<?php

namespace App\Http\Controllers;

use App\Domain\Auth\HostUser;
use App\Domain\Redemption\RedeemFailure;
use App\Domain\Redemption\SpinService;
use App\Http\ApiError;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/spins  { "parentRedemptionId": "<uuid>" }   Authorization: Bearer <JWT del host>   Idempotency-Key: <8-128>
 *
 * Juega UN giro hijo de una redención que concedió giros extra. La clave debe ser estable por giro: reintentar con la misma clave devuelve
 * el mismo resultado; una clave nueva es un giro nuevo.
 */
final class SpinController
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._\-:]{8,128}$/';

    public function __invoke(Request $request, SpinService $service): Response
    {
        /** @var HostUser $user */
        $user = $request->attributes->get('gamification_user');

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return ApiError::response('idempotencyKeyRequired', 'Falta el encabezado Idempotency-Key (8 a 128 caracteres: letras, números y ._-:).', 400);
        }

        $parent = $request->input('parentRedemptionId');

        try {
            $result = $service->spin($user->id(), $key, is_string($parent) ? $parent : '', $request->ip());
        } catch (RedeemFailure $e) {
            return ApiError::fromFailure($e);
        }

        return response($result->json, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
