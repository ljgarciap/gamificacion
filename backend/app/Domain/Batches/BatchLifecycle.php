<?php

namespace App\Domain\Batches;

use App\Domain\Audit\AuditLog;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Crypto\PrizeVault;
use App\Engine\Commitment;
use App\Engine\Pool;
use App\Engine\PrizeAssigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclo de vida de un lote (SPEC-002 §3):  draft ─commit▶ committed ─activate▶ active ─close▶ closed ─reveal▶ revealed
 *
 * Cada transición corre en UNA transacción con el lote bloqueado (FOR UPDATE), valida el estado de partida y deja su entrada
 * en la bitácora encadenada. Además, la base de datos hace cumplir las mismas reglas con triggers (ver la migración): esta
 * capa da errores claros; la base garantiza que nada pase aunque esta capa tenga un error.
 *
 * Las llamadas de red a la baliza se hacen FUERA de las transacciones, para no mantener bloqueos mientras se espera.
 */
final class BatchLifecycle
{
    /** Lotes de filas por sentencia. 500 filas × 3 parámetros queda lejos del límite de PostgreSQL. */
    private const CHUNK = 500;

    private const COLUMNS = "id, campaign_id, name, state, pool::text AS pool, total_codes,
        encode(pool_hash, 'hex') AS pool_hash, encode(codes_digest, 'hex') AS codes_digest,
        encode(chain_hash, 'hex') AS chain_hash, beacon_round, encode(commitment, 'hex') AS commitment,
        encode(seed_enc, 'hex') AS seed_enc, encode(beacon_value, 'hex') AS beacon_value,
        encode(seed_revealed, 'hex') AS seed_revealed";

    public function __construct(
        private readonly BeaconClient $beacon,
        private readonly PrizeVault $vault,
        private readonly AuditLog $audit,
    ) {}

    // --- Borrador ----------------------------------------------------------------------------------------------

    /**
     * @param  list<array{prize_id: string, quantity: int}>  $poolEntries
     * @return string id del lote
     */
    public function createDraft(
        string $campaignId,
        string $name,
        array $poolEntries,
        string $actor,
        ?Carbon $redeemableUntil = null,
    ): string {
        try {
            $pool = Pool::fromEntries($poolEntries, (int) config('gamification.max_codes_per_batch'));
        } catch (\InvalidArgumentException $e) {
            throw new InvalidBatch('Pool inválido: '.$e->getMessage(), 0, $e);
        }
        if (DB::table('campaigns')->where('id', $campaignId)->doesntExist()) {
            throw new InvalidBatch("La campaña $campaignId no existe.");
        }
        $this->assertPrizesExist($campaignId, $pool);

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $campaignId, $name, $pool, $redeemableUntil, $actor): void {
            DB::insert(
                "INSERT INTO batches (id, campaign_id, name, state, pool, pool_hash, total_codes, redeemable_until, created_by)
                 VALUES (?, ?, ?, 'draft', ?::jsonb, decode(?, 'hex'), ?, ?, ?)",
                [$id, $campaignId, $name, json_encode($this->poolToArray($pool)), bin2hex($pool->hash()),
                    $pool->total(), $redeemableUntil, $actor],
            );
            $this->audit->append($actor, 'batch.created', 'batch', $id, [
                'campaign_id' => $campaignId, 'name' => $name, 'pool' => $this->poolToArray($pool), 'total_codes' => $pool->total(),
            ]);
        });

        return $id;
    }

    /**
     * Agrega huellas de código (32 bytes cada una) a un lote en borrador, en orden de índice. Lo usa la generación de
     * códigos (B4). La base rechaza duplicados, índices fuera de rango y cualquier alta fuera del estado borrador.
     *
     * @param  list<string>  $hashes
     */
    public function addCodeHashes(string $batchId, array $hashes, string $actor): void
    {
        if ($hashes === []) {
            return;
        }
        $hashes = array_values($hashes); // los índices se calculan por posición
        foreach ($hashes as $h) {
            if (! is_string($h) || strlen($h) !== 32) {
                throw new InvalidBatch('cada huella de código debe tener 32 bytes');
            }
        }

        DB::transaction(function () use ($batchId, $hashes, $actor): void {
            $b = $this->lock($batchId);
            $this->requireState($b, 'draft', 'addCodes');
            $from = (int) DB::selectOne('SELECT count(*) AS c FROM codes WHERE batch_id = ?', [$batchId])->c;

            foreach (array_chunk($hashes, self::CHUNK, true) as $chunk) {
                $sql = [];
                $bind = [];
                foreach ($chunk as $offset => $hash) {
                    $sql[] = "(?, ?, decode(?, 'hex'))";
                    array_push($bind, $batchId, $from + $offset, bin2hex($hash));
                }
                DB::insert('INSERT INTO codes (batch_id, idx, code_hash) VALUES '.implode(',', $sql), $bind);
            }
            $this->audit->append($actor, 'batch.codes_added', 'batch', $batchId, [
                'from_idx' => $from, 'count' => count($hashes),
            ]);
        });
    }

    // --- Comprometer -------------------------------------------------------------------------------------------

    public function commit(string $batchId, string $actor): CommitmentReceipt
    {
        // Red primero, fuera de la transacción. Si luego el estado no es el esperado, no se pierde nada.
        $chain = $this->beacon->chainHash();
        $round = $this->beacon->roundAfter(
            Carbon::now()->addSeconds((int) config('gamification.beacon.margin_seconds')),
        );

        return DB::transaction(function () use ($batchId, $actor, $chain, $round): CommitmentReceipt {
            $b = $this->lock($batchId);
            $this->requireState($b, 'draft', 'commit');

            $count = (int) DB::selectOne('SELECT count(*) AS c FROM codes WHERE batch_id = ?', [$batchId])->c;
            if ($count !== (int) $b->total_codes) {
                throw new InvalidBatch("El lote tiene $count códigos y el pool define {$b->total_codes}: genere los que faltan antes de comprometer.");
            }
            $pool = $this->poolOf($b);
            $this->assertPrizesExist($b->campaign_id, $pool);

            $digest = Commitment::codesDigest($this->codeHashes($batchId), (int) $b->total_codes);
            $seed = random_bytes(32); // el CSPRNG del sistema operativo: único punto donde nace el azar del sorteo
            $commitment = Commitment::commit($batchId, $pool, $digest, $chain, $round, $seed);
            $seedEnc = $this->vault->encryptSeed($batchId, $seed);
            sodium_memzero($seed);

            DB::update(
                "UPDATE batches SET state = 'committed', codes_digest = decode(?, 'hex'), chain_hash = decode(?, 'hex'),
                        beacon_round = ?, commitment = decode(?, 'hex'), seed_enc = decode(?, 'hex'),
                        committed_at = now(), updated_at = now() WHERE id = ?",
                [bin2hex($digest), bin2hex($chain), $round, bin2hex($commitment), bin2hex($seedEnc), $batchId],
            );

            $receipt = new CommitmentReceipt(
                $batchId, bin2hex($commitment), $this->poolToArray($pool), bin2hex($pool->hash()),
                bin2hex($digest), bin2hex($chain), $round,
            );
            // La definición de los premios se fotografía en la bitácora: cambiar un monto después alteraría lo prometido.
            $this->audit->append($actor, 'batch.committed', 'batch', $batchId,
                $receipt->toArray() + ['prizes' => $this->prizeSnapshot($b->campaign_id, $pool)]);

            return $receipt;
        });
    }

    // --- Activar -----------------------------------------------------------------------------------------------

    /**
     * Pasa a activo: obtiene el valor de la ronda comprometida, mezcla la semilla, baraja el pool y guarda el premio de cada
     * código CIFRADO. Si la baliza todavía no publicó la ronda, lanza [BeaconNotReady] y el lote queda intacto.
     */
    public function activate(string $batchId, string $actor): void
    {
        $b = $this->read($batchId);
        $this->requireState($b, 'committed', 'activate');

        $value = $this->beacon->valueForRound((int) $b->beacon_round); // fuera de la transacción
        if ($value === null) {
            throw new BeaconNotReady($batchId, (int) $b->beacon_round);
        }

        DB::transaction(function () use ($batchId, $actor, $value): void {
            $b = $this->lock($batchId);
            $this->requireState($b, 'committed', 'activate');

            $pool = $this->poolOf($b);
            $seed = $this->vault->decryptSeed($batchId, hex2bin($b->seed_enc));
            $assignment = PrizeAssigner::assign($pool, $seed, $value, $batchId);
            sodium_memzero($seed);

            if (count($assignment) !== (int) $b->total_codes) {
                throw new \LogicException('la asignación no cubre todos los códigos del lote');
            }

            foreach (array_chunk($assignment, self::CHUNK, true) as $chunk) {
                $rows = [];
                $bind = [];
                foreach ($chunk as $idx => $prizeId) {
                    $rows[] = "(?::int, decode(?, 'hex'))";
                    array_push($bind, $idx, bin2hex($this->vault->encryptPrize($batchId, $idx, $prizeId)));
                }
                $bind[] = $batchId;
                $updated = DB::update(
                    'UPDATE codes SET prize_enc = v.p FROM (VALUES '.implode(',', $rows).') AS v(idx, p)
                     WHERE codes.batch_id = ? AND codes.idx = v.idx',
                    $bind,
                );
                if ($updated !== count($chunk)) {
                    throw new \LogicException('faltan códigos al asignar premios: se actualizaron '.$updated.' de '.count($chunk));
                }
            }

            DB::update(
                "UPDATE batches SET state = 'active', beacon_value = decode(?, 'hex'), activated_at = now(), updated_at = now() WHERE id = ?",
                [bin2hex($value), $batchId],
            );
            $this->audit->append($actor, 'batch.activated', 'batch', $batchId, [
                'beacon_round' => (int) $b->beacon_round, 'beacon_value' => bin2hex($value),
            ]);
        });
    }

    // --- Cerrar y revelar --------------------------------------------------------------------------------------

    public function close(string $batchId, string $actor): void
    {
        DB::transaction(function () use ($batchId, $actor): void {
            $b = $this->lock($batchId);
            $this->requireState($b, 'active', 'close');
            DB::update("UPDATE batches SET state = 'closed', closed_at = now(), updated_at = now() WHERE id = ?", [$batchId]);
            $redeemed = (int) DB::selectOne('SELECT count(*) AS c FROM codes WHERE batch_id = ? AND redeemed_at IS NOT NULL', [$batchId])->c;
            $this->audit->append($actor, 'batch.closed', 'batch', $batchId, [
                'redeemed' => $redeemed, 'total_codes' => (int) $b->total_codes,
            ]);
        });
    }

    /**
     * Publica la semilla. ANTES verifica la integridad completa del lote: que el compromiso se reproduce, que las huellas de
     * código no cambiaron y que el premio cifrado de CADA código coincide con la baraja recalculada. Si algo no cuadra,
     * NO se revela nada y se lanza un error: revelar una semilla de un lote corrupto sería publicar una verificación que falla.
     */
    public function reveal(string $batchId, string $actor): void
    {
        DB::transaction(function () use ($batchId, $actor): void {
            $b = $this->lock($batchId);
            $this->requireState($b, 'closed', 'reveal');

            $pool = $this->poolOf($b);
            $seed = $this->vault->decryptSeed($batchId, hex2bin($b->seed_enc));
            $digest = Commitment::codesDigest($this->codeHashes($batchId), (int) $b->total_codes);

            if (bin2hex($digest) !== $b->codes_digest) {
                throw new \RuntimeException("Integridad: las huellas de código del lote $batchId ya no coinciden con las comprometidas.");
            }
            $recomputed = Commitment::commit($batchId, $pool, $digest, hex2bin($b->chain_hash), (int) $b->beacon_round, $seed);
            if (bin2hex($recomputed) !== $b->commitment) {
                throw new \RuntimeException("Integridad: el compromiso del lote $batchId no se reproduce.");
            }
            $expected = PrizeAssigner::assign($pool, $seed, hex2bin($b->beacon_value), $batchId);
            $this->assertStoredAssignmentMatches($batchId, $expected);

            DB::update(
                "UPDATE batches SET state = 'revealed', seed_revealed = decode(?, 'hex'), revealed_at = now(), updated_at = now() WHERE id = ?",
                [bin2hex($seed), $batchId],
            );
            $this->audit->append($actor, 'batch.revealed', 'batch', $batchId, ['seed' => bin2hex($seed)]);
            sodium_memzero($seed);
        });
    }

    // --- Internos ----------------------------------------------------------------------------------------------

    /** @param list<string> $expected premio esperado por índice */
    private function assertStoredAssignmentMatches(string $batchId, array $expected): void
    {
        $seen = 0;
        $rows = DB::cursor("SELECT idx, encode(prize_enc, 'hex') AS p FROM codes WHERE batch_id = ? ORDER BY idx", [$batchId]);
        foreach ($rows as $r) {
            $idx = (int) $r->idx;
            if ($r->p === null || $this->vault->decryptPrize($batchId, $idx, hex2bin($r->p)) !== ($expected[$idx] ?? null)) {
                throw new \RuntimeException("Integridad: el premio guardado del código $idx del lote $batchId no coincide con la baraja recalculada.");
            }
            $seen++;
        }
        if ($seen !== count($expected)) {
            throw new \RuntimeException("Integridad: el lote $batchId tiene $seen códigos y la baraja define ".count($expected).'.');
        }
    }

    private function read(string $batchId): object
    {
        return DB::selectOne('SELECT '.self::COLUMNS.' FROM batches WHERE id = ?', [$batchId])
            ?? throw new InvalidBatch("El lote $batchId no existe.");
    }

    private function lock(string $batchId): object
    {
        return DB::selectOne('SELECT '.self::COLUMNS.' FROM batches WHERE id = ? FOR UPDATE', [$batchId])
            ?? throw new InvalidBatch("El lote $batchId no existe.");
    }

    private function requireState(object $batch, string $expected, string $attempted): void
    {
        if ($batch->state !== $expected) {
            throw new InvalidTransition($batch->id, $batch->state, $attempted);
        }
    }

    private function poolOf(object $batch): Pool
    {
        return Pool::fromEntries(json_decode($batch->pool, true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return list<array{prize_id: string, quantity: int}> */
    private function poolToArray(Pool $pool): array
    {
        return array_map(
            static fn (array $e): array => ['prize_id' => $e[0], 'quantity' => $e[1]],
            $pool->entries(),
        );
    }

    /** @return \Generator<int, string> huellas (binario) en orden de índice */
    private function codeHashes(string $batchId): \Generator
    {
        $rows = DB::cursor("SELECT encode(code_hash, 'hex') AS h FROM codes WHERE batch_id = ? ORDER BY idx", [$batchId]);
        foreach ($rows as $r) {
            yield hex2bin($r->h);
        }
    }

    private function assertPrizesExist(string $campaignId, Pool $pool): void
    {
        $ids = array_column($pool->entries(), 0);
        $known = DB::table('prizes')->where('campaign_id', $campaignId)->whereIn('prize_id', $ids)->pluck('prize_id')->all();
        $missing = array_values(array_diff($ids, array_map('strval', $known)));
        if ($missing !== []) {
            throw new InvalidBatch('El pool usa premios que la campaña no define: '.implode(', ', $missing));
        }
    }

    /** @return list<array<string, mixed>> */
    private function prizeSnapshot(string $campaignId, Pool $pool): array
    {
        return DB::table('prizes')
            ->where('campaign_id', $campaignId)
            ->whereIn('prize_id', array_column($pool->entries(), 0))
            ->orderBy('prize_id')
            ->get(['prize_id', 'type', 'label', 'amount'])
            ->map(static fn ($r): array => ['prize_id' => $r->prize_id, 'type' => $r->type, 'label' => $r->label, 'amount' => $r->amount === null ? null : (int) $r->amount])
            ->all();
    }
}
