<?php

namespace App\Http\Controllers\Dev;

use App\Domain\Dev\DemoCodes;
use Illuminate\Http\JsonResponse;

/** GET /v1/dev/demo-codes — SOLO desarrollo local. Códigos de demostración que todavía se pueden canjear. */
final class DevDemoCodesController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(
            ['codes' => array_map(
                static fn (array $c): array => ['code' => $c['code'], 'game' => $c['game'], 'description' => $c['description']],
                DemoCodes::available(),
            )],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
