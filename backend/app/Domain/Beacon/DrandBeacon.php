<?php

namespace App\Domain\Beacon;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de drand (https://drand.love). Datos comprobados contra la API real:
 *  - la ronda r se emite en `genesis + (r-1) * period`; la ronda vigente en t es `floor((t-genesis)/period) + 1`;
 *  - una ronda futura responde HTTP 425 (Too Early) y se trata como "todavía no", no como error;
 *  - `randomness` = SHA-256(`signature`). Se comprueba esa relación y que la ronda devuelta sea la pedida.
 *
 * Qué NO hace: no verifica la firma BLS de la ronda. Eso lo hace quien audita, con el verificador independiente y consultando
 * drand por su cuenta (ver verifier/gamif_verify.py). Lo que se guarda (cadena, ronda, valor) basta para que lo haga.
 */
final class DrandBeacon implements BeaconClient
{
    private ?array $info = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $chainHashHex,
        private readonly int $timeoutSeconds = 5,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $chainHashHex) !== 1) {
            throw new \InvalidArgumentException('chain_hash debe ser 64 caracteres hexadecimales en minúscula');
        }
    }

    public function chainHash(): string
    {
        return hex2bin($this->chainHashHex);
    }

    public function roundAfter(\DateTimeInterface $time): int
    {
        $info = $this->info();
        $elapsed = $time->getTimestamp() - $info['genesis_time'];
        if ($elapsed < 0) {
            throw new BeaconUnavailable('la hora indicada es anterior al génesis de la cadena');
        }

        return intdiv($elapsed, $info['period']) + 2; // la vigente es +1; la primera posterior a $time es +2
    }

    public function valueForRound(int $round): ?string
    {
        if ($round < 1) {
            throw new \InvalidArgumentException('ronda inválida');
        }
        $response = $this->get("/{$this->chainHashHex}/public/{$round}");

        if (in_array($response->status(), [404, 425], true)) {
            return null; // todavía no publicada
        }
        if (! $response->successful()) {
            throw new BeaconUnavailable("drand respondió HTTP {$response->status()}");
        }

        $body = $response->json();
        $randomness = $body['randomness'] ?? null;
        $signature = $body['signature'] ?? null;
        if (($body['round'] ?? null) !== $round
            || ! is_string($randomness) || preg_match('/^[0-9a-f]{64}$/', $randomness) !== 1
            || ! is_string($signature) || preg_match('/^[0-9a-f]+$/', $signature) !== 1) {
            throw new BeaconUnavailable('respuesta de drand incoherente (ronda o formato)');
        }
        if (hash('sha256', hex2bin($signature)) !== $randomness) {
            throw new BeaconUnavailable('respuesta de drand incoherente: randomness no es SHA-256(signature)');
        }

        return hex2bin($randomness);
    }

    /** @return array{genesis_time: int, period: int} */
    private function info(): array
    {
        if ($this->info !== null) {
            return $this->info;
        }
        $response = $this->get("/{$this->chainHashHex}/info");
        if (! $response->successful()) {
            throw new BeaconUnavailable("drand /info respondió HTTP {$response->status()}");
        }
        $b = $response->json();
        // Se exige que la cadena que responde sea la configurada: así no se acepta una baliza de otra cadena.
        if (($b['hash'] ?? null) !== $this->chainHashHex
            || ! is_int($b['genesis_time'] ?? null) || ! is_int($b['period'] ?? null) || $b['period'] < 1) {
            throw new BeaconUnavailable('drand /info no corresponde a la cadena configurada');
        }

        return $this->info = ['genesis_time' => $b['genesis_time'], 'period' => $b['period']];
    }

    private function get(string $path): \Illuminate\Http\Client\Response
    {
        try {
            /** @var PendingRequest $req */
            // Se reintentan los fallos de conexión y los errores 5xx (una API pública los tiene de forma transitoria: se vio
            // un 500 aislado de drand mientras se esperaba una ronda). NO se reintenta un 425 ("todavía no") ni un 404: son
            // respuestas válidas. Si tras los reintentos sigue fallando, el llamador decide cuándo volver a intentar.
            $req = Http::timeout($this->timeoutSeconds)
                ->retry(3, 300, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->acceptJson();

            return $req->get(rtrim($this->baseUrl, '/').$path);
        } catch (ConnectionException $e) {
            throw new BeaconUnavailable('no se pudo contactar a drand: '.$e->getMessage(), 0, $e);
        }
    }
}
