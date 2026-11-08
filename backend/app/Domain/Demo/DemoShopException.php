<?php

namespace App\Domain\Demo;

/** Fallo de negocio de la tienda de la muestra, con el código y el estado HTTP que ve el cliente. */
final class DemoShopException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
