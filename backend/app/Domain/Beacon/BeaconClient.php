<?php

namespace App\Domain\Beacon;

/**
 * Baliza pública de aleatoriedad (SPEC-002 §3). El lote compromete la RONDA antes de que exista su valor; al activar se
 * consulta ese valor y se mezcla con la semilla secreta.
 */
interface BeaconClient
{
    /** Identificador de la cadena (bytes). Va dentro del compromiso. */
    public function chainHash(): string;

    /** Primera ronda cuyo valor se publica DESPUÉS de $time. */
    public function roundAfter(\DateTimeInterface $time): int;

    /**
     * Valor aleatorio (bytes) de la ronda, o null si todavía no se publicó. null NO es un error: se reintenta más tarde.
     *
     * @throws BeaconUnavailable si la baliza no responde o su respuesta es incoherente
     */
    public function valueForRound(int $round): ?string;
}
