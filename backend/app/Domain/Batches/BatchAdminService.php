<?php

namespace App\Domain\Batches;

use App\Domain\Admin\AdminException;
use App\Domain\Audit\AuditLog;
use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\InvalidCodeFormat;
use App\Domain\Crypto\CodeHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lotes del panel (SPEC-003 RF-36/37). Valida la entrada y traduce los errores del ciclo de vida a errores de API; las transiciones y sus
 * invariantes siguen siendo de `BatchLifecycle` y de los triggers de la base. Nada de lo que sale de aquí contiene la semilla cifrada,
 * el premio cifrado de un código ni el premio de un código que nadie ha canjeado: antes de revelar, la baraja es secreta.
 */
final class BatchAdminService
{
    private const MAX_PAGE = 200;

    public function __construct(
        private readonly BatchLifecycle $lifecycle,
        private readonly CodeHasher $hasher,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(?string $campaignId, ?string $state): array
    {
        if ($state !== null && ! in_array($state, ['draft', 'committed', 'active', 'closed', 'revealed'], true)) {
            throw $this->invalid('Estado desconocido.');
        }
        $rows = DB::select(
            "SELECT b.id, b.campaign_id, b.name, b.state, b.total_codes, b.redeemable_until, b.created_at, b.committed_at, b.activated_at, b.closed_at, b.revealed_at,
                    -- Sin recorrer `codes` (hasta un millón de filas por lote): fuera del borrador los códigos son todos los del pool, y cada canje es una fila de `redemptions` (índice por lote).
                    CASE WHEN b.state = 'draft' THEN (SELECT count(*) FROM codes c WHERE c.batch_id = b.id) ELSE b.total_codes END AS codes,
                    (SELECT count(*) FROM redemptions r WHERE r.batch_id = b.id) AS redeemed
             FROM batches b WHERE (?::uuid IS NULL OR b.campaign_id = ?::uuid) AND (?::text IS NULL OR b.state = ?)
             ORDER BY b.created_at DESC, b.id LIMIT 500",
            [$campaignId !== null ? $this->uuid($campaignId) : null, $campaignId, $state, $state],
        );

        return array_map(fn (object $r): array => [
            'id' => $r->id, 'campaignId' => $r->campaign_id, 'name' => $r->name, 'state' => $r->state, 'totalCodes' => (int) $r->total_codes,
            'generatedCodes' => (int) $r->codes, 'redeemed' => (int) $r->redeemed, 'redeemableUntil' => $r->redeemable_until,
            'createdAt' => $r->created_at, 'committedAt' => $r->committed_at, 'activatedAt' => $r->activated_at, 'closedAt' => $r->closed_at, 'revealedAt' => $r->revealed_at,
        ], $rows);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $b = DB::selectOne(
            "SELECT id, campaign_id, name, state, pool::text AS pool, total_codes, redeemable_until, created_by, created_at,
                    encode(pool_hash, 'hex') AS pool_hash, encode(codes_digest, 'hex') AS codes_digest, encode(chain_hash, 'hex') AS chain_hash,
                    beacon_round, encode(commitment, 'hex') AS commitment, encode(beacon_value, 'hex') AS beacon_value,
                    encode(seed_revealed, 'hex') AS seed_revealed, committed_at, activated_at, closed_at, revealed_at
             FROM batches WHERE id = ?", [$this->uuid($id)]) ?? throw $this->notFound();

        $generated = $b->state === 'draft' ? (int) DB::selectOne('SELECT count(*) AS c FROM codes WHERE batch_id = ?', [$b->id])->c : (int) $b->total_codes;
        $redeemed = (int) DB::selectOne('SELECT count(*) AS c FROM redemptions WHERE batch_id = ?', [$b->id])->c;
        $byPrize = DB::select('SELECT prize_id, count(*) AS n FROM redemptions WHERE batch_id = ? GROUP BY prize_id ORDER BY prize_id', [$b->id]);

        return [
            'id' => $b->id, 'campaignId' => $b->campaign_id, 'name' => $b->name, 'state' => $b->state,
            'pool' => json_decode($b->pool), 'poolHash' => $b->pool_hash, 'totalCodes' => (int) $b->total_codes,
            'generatedCodes' => $generated, 'redeemed' => $redeemed,
            'redeemedByPrize' => array_map(static fn (object $r): array => ['prizeId' => $r->prize_id, 'count' => (int) $r->n], $byPrize),
            'redeemableUntil' => $b->redeemable_until, 'createdBy' => $b->created_by, 'createdAt' => $b->created_at,
            // Compromiso público (RF-21). La semilla solo aparece una vez revelada.
            'commitment' => $b->commitment, 'codesDigest' => $b->codes_digest, 'chainHash' => $b->chain_hash,
            'beaconRound' => $b->beacon_round === null ? null : (int) $b->beacon_round, 'beaconValue' => $b->beacon_value, 'seed' => $b->seed_revealed,
            'committedAt' => $b->committed_at, 'activatedAt' => $b->activated_at, 'closedAt' => $b->closed_at, 'revealedAt' => $b->revealed_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return string id del lote
     */
    public function create(array $in, string $actor): string
    {
        $campaignId = $in['campaignId'] ?? null;
        $name = $in['name'] ?? null;
        $pool = $in['pool'] ?? null;
        if (! is_string($campaignId) || ! is_string($name) || ! is_array($pool) || ! array_is_list($pool) || $pool === []) {
            throw $this->invalid('Indica «campaignId», «name» y un «pool» con al menos un premio.');
        }
        if (preg_match('/^[^\p{Cc}]{1,120}$/u', $name) !== 1 || trim($name) === '') {
            throw $this->invalid('«name» debe tener entre 1 y 120 caracteres, sin caracteres de control.');
        }
        $entries = [];
        foreach ($pool as $e) {
            if (! is_array($e) || ! is_string($e['prizeId'] ?? null) || ! is_int($e['quantity'] ?? null) || $e['quantity'] < 1) {
                throw $this->invalid('Cada entrada del pool lleva «prizeId» (texto) y «quantity» (entero positivo).');
            }
            $entries[] = ['prize_id' => $e['prizeId'], 'quantity' => $e['quantity']];
        }
        $until = null;
        if (($in['redeemableUntil'] ?? null) !== null) {
            try {
                $until = is_string($in['redeemableUntil']) ? Carbon::parse($in['redeemableUntil']) : throw new \InvalidArgumentException;
            } catch (\Throwable) {
                throw $this->invalid('«redeemableUntil» debe ser una fecha ISO 8601.');
            }
        }
        if (DB::table('campaigns')->where('id', $this->uuid($campaignId))->doesntExist()) {
            throw new AdminException('notFound', 'La campaña no existe.', 404);
        }

        try {
            return $this->lifecycle->createDraft($campaignId, $name, $entries, $actor, $until);
        } catch (InvalidBatch $e) {
            throw $this->invalid($e->getMessage());
        }
    }

    public function commit(string $id, string $actor): array
    {
        $this->uuid($id);

        return $this->transition($id, fn () => $this->lifecycle->commit($id, $actor)->toArray());
    }

    public function close(string $id, string $actor): void
    {
        $this->uuid($id);
        $this->transition($id, fn () => $this->lifecycle->close($id, $actor));
    }

    public function reveal(string $id, string $actor): void
    {
        $this->uuid($id);
        $this->transition($id, fn () => $this->lifecycle->reveal($id, $actor));
    }

    /**
     * Canjes de un lote, del más reciente al más antiguo. `user_id` es el identificador que envió el host (no un dato personal por sí mismo,
     * pero sí quien ganó): por eso esta consulta es solo de lectura y queda para roles con acceso a lotes.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function redemptions(string $id, int $page, int $perPage): array
    {
        $this->uuid($id);
        if (DB::table('batches')->where('id', $id)->doesntExist()) {
            throw $this->notFound();
        }
        $page = max(1, $page);
        $perPage = min(self::MAX_PAGE, max(1, $perPage));
        $rows = DB::select(
            'SELECT r.id, r.user_id, r.prize_id, r.created_at, c.idx FROM redemptions r JOIN codes c ON c.id = r.code_id
             WHERE r.batch_id = ? ORDER BY r.created_at DESC, r.id LIMIT ? OFFSET ?',
            [$id, $perPage, ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(static fn (object $r): array => ['id' => $r->id, 'userId' => $r->user_id, 'prizeId' => $r->prize_id, 'index' => (int) $r->idx, 'redeemedAt' => $r->created_at], $rows),
            'total' => (int) DB::selectOne('SELECT count(*) AS c FROM redemptions WHERE batch_id = ?', [$id])->c,
            'page' => $page, 'perPage' => $perPage,
        ];
    }

    /**
     * Busca un código por su texto (RF-37). Se recibe por el cuerpo de un POST para que no quede en registros de acceso ni en el historial.
     * Si NO ha sido canjeado no se dice qué premio tiene: eso es el secreto del sorteo. La consulta queda en la bitácora.
     *
     * @return array<string, mixed>
     */
    public function lookupCode(string $text, string $actor): array
    {
        try {
            $hash = $this->hasher->hash(CodeFormat::normalize($text));
        } catch (InvalidCodeFormat) {
            throw new AdminException('codeInvalid', 'El texto no es un código válido.', 422);
        }
        $c = DB::selectOne(
            "SELECT c.batch_id, c.idx, c.redeemed_at, c.redeemed_by, b.name, b.state, r.prize_id
             FROM codes c JOIN batches b ON b.id = c.batch_id LEFT JOIN redemptions r ON r.code_id = c.id
             WHERE c.code_hash = decode(?, 'hex')", [bin2hex($hash)]);
        $this->audit->append($actor, 'code.lookup', 'code', substr(bin2hex($hash), 0, 16), ['found' => $c !== null]);
        if ($c === null) {
            throw new AdminException('notFound', 'No existe un código con ese texto.', 404);
        }

        return [
            'batchId' => $c->batch_id, 'batchName' => $c->name, 'batchState' => $c->state, 'index' => (int) $c->idx,
            'redeemed' => $c->redeemed_at !== null, 'redeemedAt' => $c->redeemed_at, 'redeemedBy' => $c->redeemed_by, 'prizeId' => $c->prize_id,
        ];
    }

    /** Traduce los errores del ciclo de vida a errores de API. */
    private function transition(string $id, callable $run): mixed
    {
        if (DB::table('batches')->where('id', $id)->doesntExist()) {
            throw $this->notFound();
        }
        try {
            return $run();
        } catch (InvalidTransition $e) {
            throw new AdminException('wrongState', $e->getMessage(), 409);
        } catch (InvalidBatch $e) {
            throw new AdminException('batchNotReady', $e->getMessage(), 409);
        }
    }

    private function uuid(string $id): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            throw $this->notFound();
        }

        return $id;
    }

    private function notFound(): AdminException
    {
        return new AdminException('notFound', 'El lote no existe.', 404);
    }

    private function invalid(string $message): AdminException
    {
        return new AdminException('validationFailed', $message, 422);
    }
}
