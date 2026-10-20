<?php

namespace App\Domain\Redemption;

/**
 * Resultado de redimir un código. `json` es EXACTAMENTE el cuerpo que se responde (y el que se guardó): se devuelve tal cual, sin
 * decodificar y volver a codificar, para que `{}` no se convierta en `[]` y un reintento sea idéntico byte a byte.
 */
final readonly class RedemptionResult
{
    public function __construct(
        public string $json,
        public string $redemptionId,
        /** true si es la repetición de una redención ya hecha (mismo usuario y misma Idempotency-Key, o código ya jugado). */
        public bool $replayed,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return json_decode($this->json, true, 512, JSON_THROW_ON_ERROR);
    }
}
