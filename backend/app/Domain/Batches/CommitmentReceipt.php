<?php

namespace App\Domain\Batches;

/** Lo que se PUBLICA al comprometer un lote. La semilla no está aquí: se revela recién al cerrar la campaña. */
final readonly class CommitmentReceipt
{
    /**
     * @param  list<array{prize_id: string, quantity: int}>  $pool
     */
    public function __construct(
        public string $batchId,
        public string $commitmentHex,
        public array $pool,
        public string $poolHashHex,
        public string $codesDigestHex,
        public string $chainHashHex,
        public int $beaconRound,
        public int $reserve = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'commitment' => $this->commitmentHex,
            'pool' => $this->pool,
            'pool_hash' => $this->poolHashHex,
            'codes_digest' => $this->codesDigestHex,
            'chain_hash' => $this->chainHashHex,
            'beacon_round' => $this->beaconRound,
        ] + ($this->reserve > 0 ? ['reserve' => $this->reserve, 'commit_version' => 2] : []);
    }
}
