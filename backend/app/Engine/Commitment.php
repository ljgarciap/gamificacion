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
    /**
     * Resumen del conjunto de huellas de código (cada una de 32 bytes), en orden de índice.
     *
     * Acepta un arreglo o un flujo (generador) y calcula el hash de forma incremental, para no cargar un millón de
     * huellas en memoria. Con un flujo hay que indicar cuántas son; se comprueba que lleguen exactamente esas.
     *
     * @param  iterable<string>  $codeHashes
     */
    public static function codesDigest(iterable $codeHashes, ?int $count = null): string
    {
        $count ??= is_countable($codeHashes) ? count($codeHashes) : null;
        if ($count === null) {
            throw new \InvalidArgumentException('con un flujo de huellas hay que indicar cuántas son');
        }

        $ctx = hash_init('sha256');
        hash_update($ctx, Domain::CODES.Bytes::be32($count));
        $seen = 0;
        foreach ($codeHashes as $h) {
            if (! is_string($h) || strlen($h) !== 32) {
                throw new \InvalidArgumentException('cada huella de código debe tener 32 bytes');
            }
            hash_update($ctx, $h);
            $seen++;
        }
        if ($seen !== $count) {
            throw new \InvalidArgumentException("se esperaban $count huellas y llegaron $seen");
        }

        return hash_final($ctx, true);
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

    /**
     * Compromiso de un lote CON reserva (R ≥ 1): igual que v1 pero con su propio dominio y R explícito. El pool lleva N + R
     * posiciones; N ya va dentro del resumen de huellas.
     */
    public static function commitV2(
        string $batchId,
        Pool $pool,
        int $reserve,
        string $codesDigest,
        string $chainHash,
        int $beaconRound,
        string $seed,
    ): string {
        if (strlen($codesDigest) !== 32 || strlen($seed) !== 32) {
            throw new \InvalidArgumentException('digest y semilla deben tener 32 bytes');
        }
        self::assertReserve($pool, $reserve);

        return hash('sha256',
            Domain::COMMIT_V2
            .Bytes::lp($batchId)
            .Bytes::be32($reserve)
            .$pool->hash()
            .$codesDigest
            .Bytes::lp($chainHash)
            .Bytes::be64($beaconRound)
            .$seed,
            true);
    }

    /** Versión canónica del compromiso: R = 0 ⇒ 1, R > 0 ⇒ 2. No hay otra combinación válida. */
    public static function versionFor(int $reserve): int
    {
        if ($reserve < 0) {
            throw new \InvalidArgumentException('la reserva no puede ser negativa');
        }

        return $reserve === 0 ? 1 : 2;
    }

    /** R ≥ 1 y debe quedar al menos un código: el pool tiene N + R posiciones. */
    public static function assertReserve(Pool $pool, int $reserve): void
    {
        if ($reserve < 1 || $reserve >= $pool->total()) {
            throw new \InvalidArgumentException('la reserva debe ser ≥ 1 y menor que el total del pool');
        }
    }

    /** Igual que [mixSeed] con el dominio v2 (lotes con reserva). */
    public static function mixSeedV2(string $seed, string $beacon, string $batchId): string
    {
        if (strlen($seed) !== 32) {
            throw new \InvalidArgumentException('la semilla debe tener 32 bytes');
        }

        return hash_hmac('sha256', Domain::MIX_V2.Bytes::lp($beacon).Bytes::lp($batchId), $seed, true);
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
