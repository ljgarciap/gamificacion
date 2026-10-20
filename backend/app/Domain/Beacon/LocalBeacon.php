<?php

namespace App\Domain\Beacon;

/**
 * Baliza LOCAL de desarrollo. NO es aleatoria: el "valor" de una ronda es un hash determinista de su número, así que cualquiera puede
 * calcularlo de antemano. Existe solo para recorrer el flujo completo en desarrollo sin esperar a drand (~1 minuto por lote).
 *
 * Nunca debe usarse con premios reales: el resultado de un lote activado con ella es predecible por quien conozca la semilla del lote. Por eso
 * el contenedor se niega a construirla fuera de local/testing (ver AppServiceProvider).
 */
final class LocalBeacon implements BeaconClient
{
    public const CHAIN = 'gamif-local-beacon-NO-ES-ALEATORIA';

    public function chainHash(): string
    {
        return hash('sha256', self::CHAIN, true);
    }

    public function roundAfter(\DateTimeInterface $time): int
    {
        return $time->getTimestamp() + 1;
    }

    public function valueForRound(int $round): ?string
    {
        return hash('sha256', 'gamif-local-beacon/'.$round, true);
    }
}
