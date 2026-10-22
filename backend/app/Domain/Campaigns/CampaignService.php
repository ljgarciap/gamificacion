<?php

namespace App\Domain\Campaigns;

use App\Domain\Admin\AdminException;
use App\Domain\Audit\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campañas y premios del panel (SPEC-003 RF-35). Valida la entrada (tipos, longitudes, charset: amenaza T10) y delega las invariantes en la
 * base: una campaña con un lote comprometido no cambia sus reglas ni los premios que ese lote usa (triggers de SPEC-002). Esos rechazos se
 * traducen a `409 campaignFrozen`. El juego se valida a fondo al COMPROMETER el lote (`Presentation::assertRepresentable`), no aquí.
 */
final class CampaignService
{
    public const GAMES = ['roulette', 'scratch', 'memory', 'dice', 'bomb', 'bingo', 'poker'];

    public const PRIZE_TYPES = ['money', 'coupon', 'points', 'extraSpin', 'none'];

    private const MAX_JSON_BYTES = 16384;

    private const MAX_AMOUNT = 1_000_000_000_000;

    public function __construct(private readonly AuditLog $audit) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $rows = DB::select(
            "SELECT c.id, c.name, c.game_type, c.used_code_behavior, c.title, c.created_at, c.updated_at,
                    (SELECT count(*) FROM prizes p WHERE p.campaign_id = c.id) AS prizes,
                    (SELECT count(*) FROM batches b WHERE b.campaign_id = c.id) AS batches,
                    EXISTS (SELECT 1 FROM batches b WHERE b.campaign_id = c.id AND b.state <> 'draft') AS frozen
             FROM campaigns c ORDER BY c.created_at DESC, c.id");

        return array_map(fn (object $r): array => [
            'id' => $r->id, 'name' => $r->name, 'gameType' => $r->game_type, 'usedCodeBehavior' => $r->used_code_behavior, 'title' => $r->title,
            'prizeCount' => (int) $r->prizes, 'batchCount' => (int) $r->batches, 'frozen' => (bool) $r->frozen, 'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at,
        ], $rows);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $c = DB::selectOne(
            "SELECT id, name, game_type, used_code_behavior, title, theme::text AS theme, game_config::text AS game_config, created_at, updated_at,
                    EXISTS (SELECT 1 FROM batches b WHERE b.campaign_id = campaigns.id AND b.state <> 'draft') AS frozen
             FROM campaigns WHERE id = ?", [$this->uuid($id)]) ?? throw new AdminException('notFound', 'La campaña no existe.', 404);

        return [
            'id' => $c->id, 'name' => $c->name, 'gameType' => $c->game_type, 'usedCodeBehavior' => $c->used_code_behavior, 'title' => $c->title,
            'theme' => json_decode($c->theme), 'gameConfig' => json_decode($c->game_config), 'frozen' => (bool) $c->frozen,
            'createdAt' => $c->created_at, 'updatedAt' => $c->updated_at,
            'prizes' => array_map(fn (object $p): array => $this->prizeOut($p),
                DB::select('SELECT prize_id, type, label, amount, meta::text AS meta FROM prizes WHERE campaign_id = ? ORDER BY created_at, prize_id', [$c->id])),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return string id de la campaña
     */
    public function create(array $in, string $actor): string
    {
        $v = $this->campaignFields($in, true);
        $prizes = $in['prizes'] ?? [];
        if (! is_array($prizes) || ! array_is_list($prizes)) {
            throw $this->invalid('«prizes» debe ser una lista.');
        }
        $checked = array_map(fn ($p) => $this->prizeFields(is_array($p) ? $p : [], true), $prizes);
        $ids = array_column($checked, 'prize_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw $this->invalid('Hay premios con el mismo identificador.');
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $v, $checked, $actor): void {
            DB::insert(
                'INSERT INTO campaigns (id, name, game_type, used_code_behavior, title, theme, game_config) VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb)',
                [$id, $v['name'], $v['game_type'], $v['used_code_behavior'], $v['title'], $v['theme'], $v['game_config']],
            );
            foreach ($checked as $p) {
                $this->insertPrize($id, $p);
            }
            $this->audit->append($actor, 'campaign.created', 'campaign', $id, ['game_type' => $v['game_type'], 'prizes' => count($checked)]);
        });

        return $id;
    }

    /** @param array<string, mixed> $in */
    public function update(string $id, array $in, string $actor): void
    {
        $this->uuid($id);
        $v = $this->campaignFields($in, false);
        if ($v === []) {
            throw $this->invalid('No hay nada que cambiar.');
        }
        $this->frozenGuard(function () use ($id, $v, $actor): void {
            DB::transaction(function () use ($id, $v, $actor): void {
                $sets = [];
                $bind = [];
                foreach ($v as $col => $val) {
                    $sets[] = in_array($col, ['theme', 'game_config'], true) ? "$col = ?::jsonb" : "$col = ?";
                    $bind[] = $val;
                }
                $bind[] = $id;
                if (DB::update('UPDATE campaigns SET '.implode(', ', $sets).', updated_at = now() WHERE id = ?', $bind) !== 1) {
                    throw new AdminException('notFound', 'La campaña no existe.', 404);
                }
                $this->audit->append($actor, 'campaign.updated', 'campaign', $id, ['fields' => array_keys($v)]);
            });
        });
    }

    /** @param array<string, mixed> $in */
    public function addPrize(string $campaignId, array $in, string $actor): void
    {
        $this->requireCampaign($campaignId);
        $p = $this->prizeFields($in, true);
        $this->frozenGuard(function () use ($campaignId, $p, $actor): void {
            DB::transaction(function () use ($campaignId, $p, $actor): void {
                if (DB::table('prizes')->where('campaign_id', $campaignId)->where('prize_id', $p['prize_id'])->exists()) {
                    throw new AdminException('duplicate', 'Ya existe un premio con ese identificador.', 409);
                }
                $this->insertPrize($campaignId, $p);
                $this->audit->append($actor, 'campaign.prize_added', 'campaign', $campaignId, ['prize_id' => $p['prize_id'], 'type' => $p['type']]);
            });
        });
    }

    /** @param array<string, mixed> $in */
    public function updatePrize(string $campaignId, string $prizeId, array $in, string $actor): void
    {
        $this->requireCampaign($campaignId);
        $current = DB::selectOne('SELECT type, label, amount, meta::text AS meta FROM prizes WHERE campaign_id = ? AND prize_id = ?', [$campaignId, $prizeId])
            ?? throw new AdminException('notFound', 'El premio no existe.', 404);
        $merged = $in + ['type' => $current->type, 'label' => $current->label, 'amount' => $current->amount, 'meta' => json_decode($current->meta, true)];
        $p = $this->prizeFields(['prizeId' => $prizeId] + $merged, true);
        $this->frozenGuard(function () use ($campaignId, $prizeId, $p, $actor): void {
            DB::transaction(function () use ($campaignId, $prizeId, $p, $actor): void {
                DB::update(
                    'UPDATE prizes SET type = ?, label = ?, amount = ?, meta = ?::jsonb, updated_at = now() WHERE campaign_id = ? AND prize_id = ?',
                    [$p['type'], $p['label'], $p['amount'], $p['meta'], $campaignId, $prizeId],
                );
                $this->audit->append($actor, 'campaign.prize_updated', 'campaign', $campaignId, ['prize_id' => $prizeId, 'type' => $p['type']]);
            });
        });
    }

    public function deletePrize(string $campaignId, string $prizeId, string $actor): void
    {
        $this->requireCampaign($campaignId);
        $this->frozenGuard(function () use ($campaignId, $prizeId, $actor): void {
            DB::transaction(function () use ($campaignId, $prizeId, $actor): void {
                if (DB::delete('DELETE FROM prizes WHERE campaign_id = ? AND prize_id = ?', [$campaignId, $prizeId]) !== 1) {
                    throw new AdminException('notFound', 'El premio no existe.', 404);
                }
                $this->audit->append($actor, 'campaign.prize_deleted', 'campaign', $campaignId, ['prize_id' => $prizeId]);
            });
        });
    }

    // --- Validación ------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed> columnas listas para escribir (en edición, solo las presentes)
     */
    private function campaignFields(array $in, bool $all): array
    {
        $out = [];
        $present = fn (string $k): bool => array_key_exists($k, $in);
        if ($all || $present('name')) {
            $out['name'] = $this->text($in['name'] ?? null, 'name', 120, true);
        }
        if ($all || $present('gameType')) {
            if (! in_array($in['gameType'] ?? null, self::GAMES, true)) {
                throw $this->invalid('«gameType» debe ser uno de: '.implode(', ', self::GAMES).'.');
            }
            $out['game_type'] = $in['gameType'];
        }
        if ($all || $present('usedCodeBehavior')) {
            $value = $in['usedCodeBehavior'] ?? 'rejectOnly';
            if (! in_array($value, ['showPrevious', 'rejectOnly'], true)) {
                throw $this->invalid('«usedCodeBehavior» debe ser showPrevious o rejectOnly.');
            }
            $out['used_code_behavior'] = $value;
        }
        if ($all || $present('title')) {
            $out['title'] = ($in['title'] ?? null) === null ? null : $this->text($in['title'], 'title', 120, true);
        }
        foreach (['theme' => 'theme', 'gameConfig' => 'game_config'] as $field => $col) {
            if ($all || $present($field)) {
                $out[$col] = $this->jsonObject($in[$field] ?? new \stdClass, $field, self::MAX_JSON_BYTES);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{prize_id: string, type: string, label: string, amount: ?int, meta: string}
     */
    private function prizeFields(array $in, bool $_all): array
    {
        $id = $in['prizeId'] ?? null;
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            throw $this->invalid('«prizeId» debe tener 1 a 64 caracteres: letras, números, guion y guion bajo.');
        }
        $type = $in['type'] ?? null;
        if (! in_array($type, self::PRIZE_TYPES, true)) {
            throw $this->invalid('«type» debe ser uno de: '.implode(', ', self::PRIZE_TYPES).'.');
        }
        $amount = $in['amount'] ?? null;
        if ($amount !== null && (! is_int($amount) || $amount < 0 || $amount > self::MAX_AMOUNT)) {
            throw $this->invalid('«amount» debe ser un entero entre 0 y '.self::MAX_AMOUNT.' (pesos, sin decimales).');
        }
        if (in_array($type, ['money', 'points'], true) && $amount === null) {
            throw $this->invalid('Un premio de dinero o puntos necesita «amount».');
        }

        $meta = $this->jsonObject($in['meta'] ?? new \stdClass, 'meta', 4096);
        try {
            \App\Domain\Campaigns\ExtraSpins::fromMeta($type, json_decode($meta, true));
        } catch (\InvalidArgumentException $e) {
            throw $this->invalid($e->getMessage());
        }

        return [
            'prize_id' => $id, 'type' => $type, 'label' => $this->text($in['label'] ?? null, 'label', 120, true), 'amount' => $amount,
            'meta' => $meta,
        ];
    }

    private function text(mixed $value, string $field, int $max, bool $required): string
    {
        if (! is_string($value) || ($required && trim($value) === '')) {
            throw $this->invalid("«{$field}» es obligatorio y debe ser texto.");
        }
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1 || ! mb_check_encoding($value, 'UTF-8')) {
            throw $this->invalid("«{$field}» admite hasta {$max} caracteres, sin caracteres de control.");
        }

        return $value;
    }

    /** Objeto JSON (no lista ni escalar) de tamaño acotado; devuelve su texto JSON. */
    private function jsonObject(mixed $value, string $field, int $maxBytes): string
    {
        if ($value instanceof \stdClass) {
            $value = [];
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->invalid("«{$field}» debe ser un objeto.");
        }
        $json = $value === [] ? '{}' : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > $maxBytes) {
            throw $this->invalid("«{$field}» es demasiado grande (máximo {$maxBytes} bytes).");
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function prizeOut(object $p): array
    {
        return ['prizeId' => $p->prize_id, 'type' => $p->type, 'label' => $p->label, 'amount' => $p->amount === null ? null : (int) $p->amount, 'meta' => json_decode($p->meta)];
    }

    // --- Internos --------------------------------------------------------------------------------------------------

    /** @param array{prize_id: string, type: string, label: string, amount: ?int, meta: string} $p */
    private function insertPrize(string $campaignId, array $p): void
    {
        DB::insert('INSERT INTO prizes (campaign_id, prize_id, type, label, amount, meta) VALUES (?, ?, ?, ?, ?, ?::jsonb)',
            [$campaignId, $p['prize_id'], $p['type'], $p['label'], $p['amount'], $p['meta']]);
    }

    /** Ejecuta la operación y traduce el rechazo de los triggers de congelación a un error claro. */
    private function frozenGuard(\Closure $run): void
    {
        try {
            $run();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'inmutables') || str_contains($e->getMessage(), 'comprometido')) {
                throw new AdminException('campaignFrozen', 'La campaña tiene un lote comprometido: sus reglas y los premios que usa ya no se pueden cambiar.', 409);
            }
            throw $e;
        }
    }

    private function requireCampaign(string $id): void
    {
        if (DB::table('campaigns')->where('id', $this->uuid($id))->doesntExist()) {
            throw new AdminException('notFound', 'La campaña no existe.', 404);
        }
    }

    private function uuid(string $id): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            throw new AdminException('notFound', 'La campaña no existe.', 404);
        }

        return $id;
    }

    private function invalid(string $message): AdminException
    {
        return new AdminException('validationFailed', $message, 422);
    }
}
