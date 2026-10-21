<?php

namespace App\Domain\Approvals;

/** Fallo del control dual con el código y el estado HTTP que el panel devuelve. */
final class ApprovalException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
