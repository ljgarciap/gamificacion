<?php

namespace App\Domain\Batches;

use App\Domain\Audit\AuditLog;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Crypto\PrizeVault;
use App\Domain\Redemption\Presentation;
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
        encode(seed_revealed, 'hex') AS seed_revealed, reserve_size";

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
        int $reserve = 0,
    ): string {
        try {
            $pool = Pool::fromEntries($poolEntries, (int) config('gamification.max_codes_per_batch'));
        } catch (\InvalidArgumentException $e) {
            throw new InvalidBatch('Pool inválido: '.$e->getMessage(), 0, $e);
        }
        // El pool tiene N + R posiciones: R para los giros hijos y al menos un código.
        if ($reserve < 0 || ($reserve > 0 && $reserve >= $pool->total())) {
            throw new InvalidBatch('La reserva debe ser 0 o un número menor que el total del pool.');
        }
        if (DB::table('campaigns')->where('id', $campaignId)->doesntExist()) {
            throw new InvalidBatch("La campaña $campaignId no existe.");
        }
        $this->assertPrizesExist($campaignId, $pool);
        $this->assertReserveCovers($campaignId, $pool, $reserve);

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $campaignId, $name, $pool, $redeemableUntil, $actor, $reserve): void {
            DB::insert(
                "INSERT INTO batches (id, campaign_id, name, state, pool, pool_hash, total_codes, reserve_size, redeemable_until, created_by)
                 VALUES (?, ?, ?, 'draft', ?::jsonb, decode(?, 'hex'), ?, ?, ?, ?)",
                [$id, $campaignId, $name, json_encode($this->poolToArray($pool)), bin2hex($pool->hash()),
                    $pool->total() - $reserve, $reserve, $redeemableUntil, $actor],
            );
            if ($reserve > 0) {
                DB::insert('INSERT INTO reserve_slots (batch_id, idx) SELECT ?, g FROM generate_series(0, ?) AS g', [$id, $reserve - 1]);
                DB::insert('INSERT INTO reserve_cursors (batch_id) VALUES (?)', [$id]);
            }
            $this->audit->append($actor, 'batch.created', 'batch', $id, [
                'campaign_id' => $campaignId, 'name' => $name, 'pool' => $this->poolToArray($pool), 'total_codes' => $pool->total() - $reserve,
            ] + ($reserve > 0 ? ['reserve' => $reserve] : []));
        });

        return $id;
    }

    /**
     * Agrega huellas de código (32 bytes cada una) a un lote en borrador, en orden de índice. Lo usa la generación de
     * códigos (B4). La base rechaza duplicados, índices fuera de rango y cualquier alta fuera del estado borrador.
     *
     * @param  list<string>  $hashes
     * @param  bool  $audit  false cuando quien llama registra un único resumen (p. ej. la generación por tandas)
     */
    public function addCodeHashes(string $batchId, array $hashes, string $actor, bool $audit = true): void
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

        DB::transaction(function () use ($batchId, $hashes, $actor, $audit): void {
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
            if ($audit) {
                $this->audit->append($actor, 'batch.codes_added', 'batch', $batchId, [
                    'from_idx' => $from, 'count' => count($hashes),
                ]);
            }
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
            $reserve = (int) $b->reserve_size;
            $this->assertPrizesExist($b->campaign_id, $pool);
            $this->assertReserveCovers($b->campaign_id, $pool, $reserve);

            // Mejor rechazar AHORA que descubrir, con un usuario jugando, que un premio no cabe en la ruleta.
            $campaign = DB::selectOne('SELECT game_type, game_config::text AS cfg FROM campaigns WHERE id = ?', [$b->campaign_id]);
            Presentation::assertRepresentable($b->campaign_id, $campaign->game_type, json_decode($campaign->cfg, true), $pool);

            $digest = Commitment::codesDigest($this->codeHashes($batchId), (int) $b->total_codes);
            $seed = random_bytes(32); // el CSPRNG del sistema operativo: único punto donde nace el azar del sorteo
            $commitment = $this->commitmentFor($batchId, $pool, $reserve, $digest, $chain, $round, $seed);
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
                bin2hex($digest), bin2hex($chain), $round, $reserve,
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
            $reserve = (int) $b->reserve_size;
            $deck = PrizeAssigner::assignDeck($pool, $reserve, $seed, $value, $batchId);
            $assignment = $deck['codes'];
            sodium_memzero($seed);

            if (count($assignment) !== (int) $b->total_codes || count($deck['reserve']) !== $reserve) {
                throw new \LogicException('la asignación no cubre todos los códigos y casillas de reserva del lote');
            }
            foreach ($deck['reserve'] as $slot => $prizeId) {
                $updated = DB::update(
                    "UPDATE reserve_slots SET prize_enc = decode(?, 'hex') WHERE batch_id = ? AND idx = ?",
                    [bin2hex($this->vault->encryptReservePrize($batchId, (int) $b->total_codes + $slot, $prizeId)), $batchId, $slot],
                );
                if ($updated !== 1) {
                    throw new \LogicException("falta la casilla de reserva $slot al asignar premios");
                }
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
            // clock_timestamp() y no now(): now() es la hora de INICIO de la transacción, y el cierre pudo esperar a los canjes en curso.
            DB::update("UPDATE batches SET state = 'closed', closed_at = clock_timestamp(), updated_at = now() WHERE id = ?", [$batchId]);
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
            $reserve = (int) $b->reserve_size;
            $recomputed = $this->commitmentFor($batchId, $pool, $reserve, $digest, hex2bin($b->chain_hash), (int) $b->beacon_round, $seed);
            if (bin2hex($recomputed) !== $b->commitment) {
                throw new \RuntimeException("Integridad: el compromiso del lote $batchId no se reproduce.");
            }
            $deck = PrizeAssigner::assignDeck($pool, $reserve, $seed, hex2bin($b->beacon_value), $batchId);
            $this->assertStoredAssignmentMatches($batchId, $deck['codes']);
            $this->assertStoredReserveMatches($batchId, (int) $b->total_codes, $deck['reserve']);

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

    /**
     * Las casillas de reserva guardadas coinciden con la baraja, y lo consumido es coherente: el cursor, las casillas marcadas y las
     * redenciones hijas son la misma cantidad (si no, alguien consumió fuera del servicio).
     *
     * @param  list<string>  $expected  premio esperado por casilla
     */
    private function assertStoredReserveMatches(string $batchId, int $codes, array $expected): void
    {
        $seen = 0;
        $consumed = 0;
        $rows = DB::cursor("SELECT idx, encode(prize_enc, 'hex') AS p, consumed_at FROM reserve_slots WHERE batch_id = ? ORDER BY idx", [$batchId]);
        foreach ($rows as $r) {
            $idx = (int) $r->idx;
            if ($r->p === null || $this->vault->decryptReservePrize($batchId, $codes + $idx, hex2bin($r->p)) !== ($expected[$idx] ?? null)) {
                throw new \RuntimeException("Integridad: el premio guardado de la casilla de reserva $idx del lote $batchId no coincide con la baraja recalculada.");
            }
            $seen++;
            $consumed += $r->consumed_at === null ? 0 : 1;
        }
        if ($seen !== count($expected)) {
            throw new \RuntimeException("Integridad: el lote $batchId tiene $seen casillas de reserva y la baraja define ".count($expected).'.');
        }
        $cursor = (int) (DB::selectOne('SELECT consumed FROM reserve_cursors WHERE batch_id = ?', [$batchId])->consumed ?? 0);
        $children = (int) DB::selectOne('SELECT count(*) AS c FROM redemptions WHERE batch_id = ? AND reserve_idx IS NOT NULL', [$batchId])->c;
        if ($cursor !== $consumed || $children !== $consumed) {
            throw new \RuntimeException("Integridad: la reserva del lote $batchId no cuadra (cursor $cursor, casillas consumidas $consumed, redenciones hijas $children).");
        }
    }

    /** R = 0 ⇒ compromiso v1 (sin cambios); R > 0 ⇒ v2. No hay otra combinación. */
    private function commitmentFor(string $batchId, Pool $pool, int $reserve, string $digest, string $chain, int $round, string $seed): string
    {
        return Commitment::versionFor($reserve) === 1
            ? Commitment::commit($batchId, $pool, $digest, $chain, $round, $seed)
            : Commitment::commitV2($batchId, $pool, $reserve, $digest, $chain, $round, $seed);
    }

    /**
     * Con reserva, las casillas deben alcanzar para el PEOR caso: cada posición del mazo con un premio «giro extra» puede pedir sus
     * giros, y cada giro consume una casilla. Si no alcanza, alguien ganaría un giro que no se puede entregar (stock exacto: error
     * duro, no advertencia). Sin reserva (R = 0) no hay giros hijos y se conserva el comportamiento anterior.
     */
    private function assertReserveCovers(string $campaignId, Pool $pool, int $reserve): void
    {
        if ($reserve === 0) {
            return;
        }
        $demand = 0;
        $qty = [];
        foreach ($pool->entries() as [$id, $q]) {
            $qty[$id] = $q;
        }
        $rows = DB::table('prizes')->where('campaign_id', $campaignId)->where('type', 'extraSpin')->whereIn('prize_id', array_keys($qty))
            ->get(['prize_id', DB::raw('meta::text AS meta')]);
        foreach ($rows as $r) {
            $spins = (int) ((json_decode($r->meta, true) ?: [])['extra_spins'] ?? 1);
            $demand += $qty[(string) $r->prize_id] * $spins;
        }
        if ($demand > $reserve) {
            throw new InvalidBatch("La reserva ($reserve) no cubre el peor caso: los premios de giro extra del pool piden hasta $demand giros.");
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
