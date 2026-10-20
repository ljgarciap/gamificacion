<?php

namespace App\Domain\Crypto;

/**
 * Huella de un código: HMAC-SHA256 con el pepper del servidor (R2). La base guarda SOLO esto, nunca el código en claro,
 * y sin el pepper no se puede probar códigos contra una base filtrada. Recibe el código ya normalizado
 * (mayúsculas, sin separadores); la normalización y el dígito verificador son de la generación de códigos (B4).
 */
final class CodeHasher
{
    public function __construct(private readonly ?string $pepperOverride = null) {}

    public function hash(string $normalizedCode): string
    {
        $pepper = $this->pepperOverride ?? Secrets::key('code_pepper');

        return hash_hmac('sha256', 'gamif/v1/code'.$normalizedCode, $pepper, true);
    }
}
