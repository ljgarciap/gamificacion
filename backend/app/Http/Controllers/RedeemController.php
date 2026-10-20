<?php

namespace App\Http\Controllers;

use App\Domain\Auth\HostUser;
use App\Domain\Redemption\RedeemFailure;
use App\Domain\Redemption\RedeemService;
use App\Http\ApiError;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/codes/redeem  { "code": "RULETA-K3J9-QW2X-7HC" }   Authorization: Bearer <JWT del host>   Idempotency-Key: <8-128>
 */
final class RedeemController
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._\-:]{8,128}$/';

    public function __invoke(Request $request, RedeemService $service): Response
    {
        /** @var HostUser $user */
        $user = $request->attributes->get('gamification_user');

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return ApiError::response('idempotencyKeyRequired', 'Falta el encabezado Idempotency-Key (8 a 128 caracteres: letras, números y ._-:).', 400);
        }

        $code = $request->input('code');

        try {
            $result = $service->redeem($user->id(), $key, is_string($code) ? $code : '', $request->ip());
        } catch (RedeemFailure $e) {
            return ApiError::fromFailure($e);
        }

        // El cuerpo se devuelve tal cual se guardó. Nunca se cachea: es el premio de una persona.
        return response($result->json, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
