<?php

namespace App\Http\Controllers;

use App\Domain\Auth\HostUser;
use App\Domain\Balance\BalanceProvider;
use App\Domain\Hosts\HostUnavailable;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /v1/balance → {"amount": 120000, "currency": "COP"} */
final class BalanceController
{
    public function __invoke(Request $request, BalanceProvider $balances): JsonResponse
    {
        /** @var HostUser $user */
        $user = $request->attributes->get('gamification_user');

        try {
            $amount = $balances->balanceFor($user->id());
        } catch (HostUnavailable) {
            // Nunca un saldo inventado (T21). El detalle del fallo se queda en el servidor.
            return ApiError::response('hostUnavailable', 'No pudimos consultar tu saldo. Inténtalo de nuevo en un momento.', 503, 5);
        }

        return response()->json(['amount' => $amount, 'currency' => 'COP'], 200, ['Cache-Control' => 'no-store']);
    }
}
