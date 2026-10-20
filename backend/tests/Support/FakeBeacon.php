<?php

namespace Tests\Support;

use App\Domain\Beacon\BeaconClient;
use App\Domain\Beacon\BeaconUnavailable;

/** Baliza simulada: el test decide cuándo "se publica" cada ronda. */
final class FakeBeacon implements BeaconClient
{
    /** @var array<int, string> ronda => valor (binario) */
    public array $published = [];

    public bool $down = false;

    public function __construct(public int $nextRound = 5_000_000, public string $chain = 'chain-de-prueba-0123456789abcdef') {}

    public function chainHash(): string
    {
        return $this->chain;
    }

    public function roundAfter(\DateTimeInterface $time): int
    {
        return $this->nextRound;
    }

    public function valueForRound(int $round): ?string
    {
        if ($this->down) {
            throw new BeaconUnavailable('baliza caída (simulada)');
        }

        return $this->published[$round] ?? null;
    }

    /** Publica la ronda con un valor determinista y lo devuelve. */
    public function publish(int $round, string $label = 'valor'): string
    {
        return $this->published[$round] = hash('sha256', "$label/$round", true);
    }
}
