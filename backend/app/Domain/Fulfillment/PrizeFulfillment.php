<?php

namespace App\Domain\Fulfillment;

/**
 * Entrega un aviso de acreditación a la app host. Debe LANZAR una excepción si no se pudo entregar (se reintenta con espera creciente).
 *
 * La entrega es "al menos una vez": si el proceso cae entre entregar y marcar como enviado, se vuelve a entregar. Por eso el receptor
 * debe ser idempotente usando `payload.redemption_id`, que es único por premio.
 */
interface PrizeFulfillment
{
    /** @param array<string, mixed> $payload */
    public function deliver(array $payload): void;
}
