<?php

namespace App\Domain\Batches;

/** La ronda de baliza comprometida todavía no se publicó. El lote no cambia: se reintenta más tarde (nunca se omite la baliza). */
class BeaconNotReady extends \RuntimeException
{
    public function __construct(public readonly string $batchId, public readonly int $round)
    {
        parent::__construct("La ronda $round de la baliza todavía no está disponible para el lote $batchId.");
    }
}
