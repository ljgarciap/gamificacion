<?php

namespace Tests\Support;

use App\Domain\Fulfillment\PrizeFulfillment;

/** Adaptador de prueba: registra lo entregado y falla cuando se le pide. */
final class RecordingFulfillment implements PrizeFulfillment
{
    /** @var list<array<string, mixed>> */
    public array $delivered = [];

    /** @var array<string, int> redemption_id => cuántas veces debe fallar antes de aceptar */
    public array $failTimes = [];

    public bool $alwaysFail = false;

    public string $failMessage = 'el host no respondió';

    public function deliver(array $payload): void
    {
        $id = $payload['redemption_id'] ?? '';
        if ($this->alwaysFail || ($this->failTimes[$id] ?? 0) > 0) {
            if (isset($this->failTimes[$id])) {
                $this->failTimes[$id]--;
            }
            throw new \RuntimeException($this->failMessage);
        }
        $this->delivered[] = $payload;
    }
}
