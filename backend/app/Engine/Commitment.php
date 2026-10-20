<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Compromiso y mezcla de semilla (SPEC-002 §3 y §4).
 *
 * El compromiso se publica ANTES de activar el lote y liga: el id del lote, el pool, el conjunto de huellas de
 * código, la cadena y la RONDA de baliza pública (que va dentro: el operador no puede elegir otra después) y
 * la semilla secreta. Al cerrar se revela la semilla y cualquiera lo recalcula.
 */
final class Commitment
{
    /** Resumen del conjunto de huellas de código (cada una de 32 bytes), en orden de índice. */
    public static function codesDigest(array $codeHashes): string
    {
        foreach ($codeHashes as $h) {
            if (! is_string($h) || strlen($h) !== 32) {
                throw new \InvalidArgumentException('cada huella de código debe tener 32 bytes');
            }
        }

        return hash('sha256', Domain::CODES.Bytes::be32(count($codeHashes)).implode('', $codeHashes), true);
    }

    public static function commit(
        string $batchId,
        Pool $pool,
        string $codesDigest,
        string $chainHash,
        int $beaconRound,
        string $seed,
    ): string {
        if (strlen($codesDigest) !== 32 || strlen($seed) !== 32) {
            throw new \InvalidArgumentException('digest y semilla deben tener 32 bytes');
        }

        return hash('sha256',
            Domain::COMMIT
            .Bytes::lp($batchId)
            .$pool->hash()
            .$codesDigest
            .Bytes::lp($chainHash)
            .Bytes::be64($beaconRound)
            .$seed,
            true);
    }

    /** S' = HMAC-SHA256(clave = S, "gamif/v1/mix" ‖ lp(B) ‖ lp(batch_id)). */
    public static function mixSeed(string $seed, string $beacon, string $batchId): string
    {
        if (strlen($seed) !== 32) {
            throw new \InvalidArgumentException('la semilla debe tener 32 bytes');
        }

        return hash_hmac('sha256', Domain::MIX.Bytes::lp($beacon).Bytes::lp($batchId), $seed, true);
    }
}
