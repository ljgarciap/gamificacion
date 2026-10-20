<?php

namespace App\Domain\Auth;

/** Usuario autenticado por una app host. El id se prefija con el emisor, así dos hosts nunca comparten usuarios por casualidad. */
final readonly class HostUser
{
    public function __construct(public string $issuer, public string $subject)
    {
        if ($issuer === '' || $subject === '') {
            throw new \InvalidArgumentException('emisor y sujeto son obligatorios');
        }
    }

    public function id(): string
    {
        return $this->issuer.':'.$this->subject;
    }
}
