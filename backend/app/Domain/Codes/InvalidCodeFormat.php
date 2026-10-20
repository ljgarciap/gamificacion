<?php

namespace App\Domain\Codes;

/**
 * El texto no es un código válido. Para el usuario es un solo caso ("código inválido"); la razón sirve para métricas y pruebas.
 * Importante: esto detecta errores de TIPEO, no es una medida de seguridad (la seguridad son los 50 bits aleatorios y el límite de intentos).
 */
class InvalidCodeFormat extends \DomainException
{
    public const MALFORMED = 'malformed';

    public const CHECKSUM = 'checksum';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
