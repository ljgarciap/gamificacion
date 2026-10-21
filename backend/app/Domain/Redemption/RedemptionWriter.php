<?php

namespace App\Domain\Redemption;

use App\Domain\Audit\AuditLog;
use App\Domain\Campaigns\ExtraSpins;
use App\Domain\Crypto\Secrets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registra una redención ya decidida: arma lo que se muestra, la inserta, deja el aviso de acreditación en la MISMA transacción y anota
 * la bitácora. Lo comparten el canje de un código y el giro hijo de la reserva (SPEC-003 P6c), así el cuerpo de la respuesta es el mismo.
 */
final class RedemptionWriter
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly Presentation $presentation,
    ) {}

    /**
     * @param  object  $row  lote y campaña: batch_id, campaign_id, campaign_name, game_type, used_code_behavior, title, theme, game_config
     * @param  array{code_id: int}|array{reserve_idx: int, parent: string, spin_no: int}  $source  de dónde sale el premio
     */
    public function write(object $row, string $userId, string $key, string $prizeId, array $source): RedemptionResult
    {
        $child = ! isset($source['code_id']);
        $prizeDefs = $this->prizeDefs($row->campaign_id);
        $prize = $prizeDefs[$prizeId] ?? throw new \LogicException("El premio $prizeId no está definido en la campaña {$row->campaign_id}.");
        // La presentación trabaja con el JSON del premio tal como lo entiende el cliente.
        $built = $this->presentation->build(
            $row->game_type, json_decode($row->game_config, true), array_map(static fn (array $d): array => $d['json'], $prizeDefs), $prizeId,
        );

        $id = (string) Str::uuid();
        // La hora sale del reloj de la BASE (el mismo con el que se marca el cierre del lote), no del de la aplicación: con relojes
        // distintos no se podría afirmar que una redención fue anterior al cierre.
        $playedAt = Carbon::parse(DB::selectOne('SELECT clock_timestamp() AS t')->t)->utc();
        $json = json_encode([
            'redemptionId' => $id,
            'alreadyPlayed' => false,
            'playedAt' => $playedAt->format('Y-m-d\TH:i:s.u\Z'),
        ] + ($child ? ['parentRedemptionId' => $source['parent'], 'spinNo' => $source['spin_no']] : []) + [
            'campaign' => [
                'id' => $row->campaign_id,
                'name' => $row->campaign_name,
                'gameType' => $row->game_type,
                'usedCodeBehavior' => $row->used_code_behavior,
                'title' => $row->title,
                'theme' => json_decode($row->theme), // objeto (stdClass), no arreglo: {} sigue siendo {}
                'gameConfig' => (object) $built['gameConfig'],
            ],
            'outcome' => [
                'prize' => $prize['json'],
                'extraSpins' => $prize['extraSpins'],
                'gameResult' => (object) $built['gameResult'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        DB::insert(
            'INSERT INTO redemptions (id, code_id, reserve_idx, parent_redemption_id, spin_no, batch_id, user_id, idempotency_key, prize_id, response, created_at, spins_granted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::json, ?::timestamptz, ?)',
            [$id, $source['code_id'] ?? null, $source['reserve_idx'] ?? null, $source['parent'] ?? null, $source['spin_no'] ?? null,
                $row->batch_id, $userId, $key, $prizeId, $json, $playedAt->format('Y-m-d H:i:s.uP'), $prize['extraSpins']],
        );

        // Aviso de acreditación en la MISMA transacción: o existen redención y aviso, o ninguno. Un "sin premio" no acredita nada.
        if ($prize['json']['type'] !== 'none') {
            DB::insert(
                'INSERT INTO prize_outbox (redemption_id, payload) VALUES (?, ?::jsonb)',
                [$id, json_encode([
                    'redemption_id' => $id, 'user_id' => $userId, 'campaign_id' => $row->campaign_id, 'batch_id' => $row->batch_id,
                    'prize' => $prize['json'], 'redeemed_at' => $playedAt->format('Y-m-d\TH:i:s.u\Z'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            );
        }

        // Al final: el candado de la bitácora se mantiene el menor tiempo posible. No lleva datos personales en claro.
        $ref = $this->userRef($userId);
        $this->audit->append('user:'.$ref, $child ? 'spin.redeemed' : 'code.redeemed', 'redemption', $id, [
            'batch_id' => $row->batch_id, 'prize_id' => $prizeId, 'user_ref' => $ref,
        ] + ($child ? ['parent_redemption_id' => $source['parent'], 'spin_no' => $source['spin_no'], 'reserve_idx' => $source['reserve_idx']] : [])
            // Los giros que concede esta redención (solo en lotes con reserva: sin reserva la bitácora es la de siempre): el verificador
            // comprueba con ellos que no se jugaron más de los concedidos.
            + ($prize['extraSpins'] > 0 && (int) ($row->reserve_size ?? 0) > 0 ? ['spins_granted' => $prize['extraSpins']] : []));

        return new RedemptionResult($json, $id, replayed: false);
    }

    /**
     * @return array<string, array{json: array<string, mixed>, extraSpins: int}> premios de la campaña por id; `json` es la forma
     *                                                                           que entiende el cliente (Prize.fromJson).
     */
    private function prizeDefs(string $campaignId): array
    {
        $out = [];
        foreach (DB::select('SELECT prize_id, type, label, amount, meta::text AS meta FROM prizes WHERE campaign_id = ?', [$campaignId]) as $p) {
            $meta = json_decode($p->meta, true) ?: [];
            $json = ['id' => (string) $p->prize_id, 'type' => $p->type, 'label' => $p->label];
            if ($p->amount !== null) {
                $json['amount'] = (int) $p->amount;
            }
            if (isset($meta['coupon_code']) && is_string($meta['coupon_code'])) {
                $json['couponCode'] = $meta['coupon_code'];
            }
            if (isset($meta['icon_key']) && is_string($meta['icon_key'])) {
                $json['iconKey'] = $meta['icon_key'];
            }
            $out[(string) $p->prize_id] = [
                'json' => $json,
                'extraSpins' => ExtraSpins::lenient($p->type, $meta),
            ];
        }

        return $out;
    }

    private function userRef(string $userId): string
    {
        return substr(hash_hmac('sha256', 'gamif/v1/user'.$userId, Secrets::key('code_pepper')), 0, 16);
    }
}
