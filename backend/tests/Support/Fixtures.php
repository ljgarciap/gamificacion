<?php

namespace Tests\Support;

use App\Domain\Audit\AuditLog;
use App\Domain\Batches\BatchLifecycle;
use App\Domain\Crypto\CodeHasher;
use App\Domain\Crypto\PrizeVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Arma campañas, premios y lotes para las pruebas. */
final class Fixtures
{
    public const ACTOR = 'tester';

    public function __construct(
        public readonly FakeBeacon $beacon = new FakeBeacon,
        public readonly PrizeVault $vault = new PrizeVault,
        public readonly AuditLog $audit = new AuditLog,
    ) {}

    public function lifecycle(): BatchLifecycle
    {
        return new BatchLifecycle($this->beacon, $this->vault, $this->audit);
    }

    /** Configuración de juego válida: la ruleta usa los 4 premios (con "sin premio" repetido) y el raspa los símbolos por defecto. */
    public static function gameConfig(string $gameType): array
    {
        return match ($gameType) {
            'roulette' => ['segments' => ['p50k', 'none', 'p10k', 'p100k', 'none', 'p10k', 'none', 'p50k']],
            'scratch' => ['rows' => 3, 'cols' => 3],
            default => [],
        };
    }

    /** Campaña con cuatro premios: none, p10k, p50k y p100k. */
    public function campaign(string $gameType = 'roulette', string $usedCodeBehavior = 'rejectOnly'): string
    {
        $id = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $id, 'name' => 'Campaña de prueba', 'game_type' => $gameType, 'used_code_behavior' => $usedCodeBehavior,
            'title' => 'Gira y Gana', 'game_config' => json_encode(self::gameConfig($gameType)),
        ]);
        DB::table('prizes')->insert([
            ['campaign_id' => $id, 'prize_id' => 'none', 'type' => 'none', 'label' => 'Sin premio', 'amount' => null],
            ['campaign_id' => $id, 'prize_id' => 'p10k', 'type' => 'money', 'label' => '$ 10.000', 'amount' => 10000],
            ['campaign_id' => $id, 'prize_id' => 'p50k', 'type' => 'money', 'label' => '$ 50.000', 'amount' => 50000],
            ['campaign_id' => $id, 'prize_id' => 'p100k', 'type' => 'money', 'label' => '$ 100.000', 'amount' => 100000],
        ]);

        return $id;
    }

    /** @return list<array{prize_id: string, quantity: int}> */
    public static function pool(int $none = 6, int $p10k = 3, int $p50k = 1, int $p100k = 0): array
    {
        $entries = [['prize_id' => 'none', 'quantity' => $none], ['prize_id' => 'p10k', 'quantity' => $p10k], ['prize_id' => 'p50k', 'quantity' => $p50k]];
        if ($p100k > 0) {
            $entries[] = ['prize_id' => 'p100k', 'quantity' => $p100k];
        }

        return $entries;
    }

    /** Lote en borrador, sin códigos. */
    public function draft(?string $campaignId = null, ?array $pool = null): string
    {
        return $this->lifecycle()->createDraft($campaignId ?? $this->campaign(), 'Lote de prueba', $pool ?? self::pool(), self::ACTOR);
    }

    /** Agrega $n huellas de código deterministas al lote. */
    public function addCodes(string $batchId, int $n): void
    {
        $hasher = new CodeHasher;
        $from = (int) DB::table('codes')->where('batch_id', $batchId)->count();
        $hashes = [];
        for ($i = 0; $i < $n; $i++) {
            $hashes[] = $hasher->hash('CODIGO-'.substr($batchId, 0, 8).'-'.($from + $i));
        }
        $this->lifecycle()->addCodeHashes($batchId, $hashes, self::ACTOR);
    }

    /** Lote en borrador con todos sus códigos (los que define el pool). */
    public function draftWithCodes(?array $pool = null): string
    {
        $pool ??= self::pool();
        $id = $this->draft(null, $pool);
        $this->addCodes($id, array_sum(array_column($pool, 'quantity')));

        return $id;
    }

    public function committed(?array $pool = null): string
    {
        $id = $this->draftWithCodes($pool);
        $this->lifecycle()->commit($id, self::ACTOR);

        return $id;
    }

    public function active(?array $pool = null): string
    {
        $id = $this->committed($pool);
        $this->activate($id);

        return $id;
    }

    /** Publica la ronda comprometida en la baliza simulada y activa el lote. */
    public function activate(string $batchId): void
    {
        $round = (int) DB::table('batches')->where('id', $batchId)->value('beacon_round');
        $this->beacon->publish($round);
        $this->lifecycle()->activate($batchId, self::ACTOR);
    }

    public function batchRow(string $batchId): object
    {
        return DB::selectOne(
            "SELECT id, state, total_codes, beacon_round, encode(commitment,'hex') AS commitment, encode(seed_enc,'hex') AS seed_enc,
                    encode(beacon_value,'hex') AS beacon_value, encode(seed_revealed,'hex') AS seed_revealed,
                    encode(codes_digest,'hex') AS codes_digest, encode(chain_hash,'hex') AS chain_hash, pool::text AS pool
             FROM batches WHERE id = ?", [$batchId]);
    }
}
