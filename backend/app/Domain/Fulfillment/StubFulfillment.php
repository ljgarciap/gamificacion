<?php

namespace App\Domain\Fulfillment;

use Illuminate\Support\Facades\Log;

/** Adaptador de desarrollo: solo registra el aviso. El callback firmado hacia una app host real llega con SPEC-003. */
final class StubFulfillment implements PrizeFulfillment
{
    public function deliver(array $payload): void
    {
        Log::info('Aviso de acreditación (stub)', [
            'redemption_id' => $payload['redemption_id'] ?? null,
            'prize_type' => $payload['prize']['type'] ?? null,
        ]);
    }
}
