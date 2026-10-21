<?php

namespace App\Domain\Admin;

/** Fallo de una operación del panel con el código de error y el estado HTTP que se devuelven al cliente. */
final class AdminException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
