<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Pool de premios de un lote: qué premios existen y cuántos códigos tendrá cada uno (el stock).
 * Un "sin premio" es un premio más (id propio y cantidad), no un caso especial.
 *
 * Inmutable. Las entradas se guardan en orden canónico (por los bytes UTF-8 del id), de modo que el orden
 * en que llegaron no cambia ni el hash ni la baraja.
 */
final class Pool
{
    public const MAX_TOTAL = 1_000_000;

    public const MAX_ID_BYTES = 64;

    /** @param list<array{0: string, 1: int}> $entries ordenadas por id */
    private function __construct(private readonly array $entries, private readonly int $total) {}

    /**
     * @param  iterable<array{prize_id: string, quantity: int}>  $entries
     */
    public static function fromEntries(iterable $entries, int $maxTotal = self::MAX_TOTAL): self
    {
        $seen = [];
        $total = 0;
        foreach ($entries as $entry) {
            $id = $entry['prize_id'] ?? null;
            $qty = $entry['quantity'] ?? null;

            if (! is_string($id) || $id === '' || strlen($id) > self::MAX_ID_BYTES || preg_match('//u', $id) !== 1) {
                throw new \InvalidArgumentException('id de premio inválido (vacío, de más de 64 bytes o no UTF-8)');
            }
            if (! is_int($qty) || $qty < 0) {
                throw new \InvalidArgumentException("cantidad inválida para el premio {$id}");
            }
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException("premio repetido en el pool: {$id}");
            }
            $seen[$id] = $qty;
            $total += $qty;
            if ($total > $maxTotal) {
                throw new \InvalidArgumentException('el pool supera el máximo de códigos');
            }
        }
        if ($total < 1) {
            throw new \InvalidArgumentException('el pool no tiene ningún código');
        }

        $ids = array_map('strval', array_keys($seen));
        usort($ids, static fn (string $a, string $b): int => strcmp($a, $b)); // orden por bytes
        $sorted = array_map(static fn (string $id): array => [$id, $seen[$id]], $ids);

        return new self($sorted, $total);
    }

    /** @return list<array{0: string, 1: int}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function total(): int
    {
        return $this->total;
    }

    /** Hash canónico del pool (binario, 32 bytes). */
    public function hash(): string
    {
        $body = Bytes::be32(count($this->entries));
        foreach ($this->entries as [$id, $qty]) {
            $body .= Bytes::lp($id).Bytes::be64($qty);
        }

        return hash('sha256', Domain::POOL.$body, true);
    }

    /**
     * El pool expandido: cada id repetido `quantity` veces, en orden canónico. Es lo que se baraja.
     *
     * @return list<string>
     */
    public function expanded(): array
    {
        $out = [];
        foreach ($this->entries as [$id, $qty]) {
            for ($i = 0; $i < $qty; $i++) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
