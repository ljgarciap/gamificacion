<?php

namespace App\Http\Controllers;

use App\Domain\Auth\HostUser;
use App\Domain\Balance\BalanceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /v1/balance → {"amount": 120000, "currency": "COP"} */
final class BalanceController
{
    public function __invoke(Request $request, BalanceProvider $balances): JsonResponse
    {
        /** @var HostUser $user */
        $user = $request->attributes->get('gamification_user');

        return response()->json(
            ['amount' => $balances->balanceFor($user->id()), 'currency' => 'COP'],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
