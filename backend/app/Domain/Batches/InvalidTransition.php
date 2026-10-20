<?php

namespace App\Domain\Batches;

/** Se pidió una transición que el estado actual del lote no permite. */
class InvalidTransition extends \DomainException
{
    public function __construct(public readonly string $batchId, public readonly string $from, public readonly string $attempted)
    {
        parent::__construct("El lote $batchId está en estado '$from' y no admite '$attempted'.");
    }
}
