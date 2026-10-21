<?php

namespace App\Domain\Redemption;

use App\Domain\Crypto\PrizeVault;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Giro hijo de un premio «giro extra» (SPEC-003 P6c, docs/seguridad-p6.md). El servidor decide: consume la SIGUIENTE casilla de la
 * reserva del lote (la k-ésima redención hija recibe la casilla k) en la misma transacción que crea la redención hija.
 *
 * Orden de bloqueos fijo (evita interbloqueos): lote COMPARTIDO → redención padre → cursor de la reserva → bitácora (al final).
 * El derecho a girar (`spins_granted`) se fijó al canjear el padre; aquí no se relee de la campaña.
 */
final class SpinService
{
    public function __construct(
        private readonly PrizeVault $vault,
        private readonly RedeemThrottle $throttle,
        private readonly RedemptionWriter $writer,
    ) {}

    /** @throws RedeemFailure */
    public function spin(string $userId, string $idempotencyKey, string $parentId, ?string $ip = null): RedemptionResult
    {
        $this->throttle->assertAllowed($userId, $ip);

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $parentId) !== 1) {
            $this->fail($userId, $ip, RedeemFailure::SPIN_NOT_FOUND);
        }

        // Reintento del mismo usuario con la misma clave: se repite la respuesta, no se consume otra casilla.
        if (($existing = $this->findByKey($userId, $idempotencyKey)) !== null) {
            return $this->replay($existing, $parentId);
        }

        try {
            $result = DB::transaction(fn (): RedemptionResult => $this->consume($userId, $idempotencyKey, $parentId));
        } catch (RedeemFailure $e) {
            // La transacción ya se revirtió: ahora sí se puede anotar el fallo sin que se pierda con ella.
            if ($e->countsAsFailure) {
                $this->throttle->recordFailure($userId, $ip);
            }
            throw $e;
        } catch (QueryException $e) {
            // El mismo usuario reutilizó la clave en otra petición concurrente.
            if (str_contains($e->getMessage(), 'redemptions_user_key_unique')) {
                $other = $this->findByKey($userId, $idempotencyKey);
                if ($other !== null) {
                    return $this->replay($other, $parentId);
                }
            }
            // El lote se cerró justo mientras se giraba.
            if (str_contains($e->getMessage(), 'solo se consumen casillas de lotes activos')) {
                throw new RedeemFailure(RedeemFailure::EXPIRED);
            }
            throw $e;
        }

        $this->throttle->recordSuccess($userId);

        return $result;
    }

    private function consume(string $userId, string $key, string $parentId): RedemptionResult
    {
        // Un padre ajeno, inexistente o de otro lote responde IGUAL (sin oráculo), y cuenta contra el límite de intentos.
        $parent = DB::selectOne('SELECT user_id, batch_id FROM redemptions WHERE id = ?', [$parentId]);
        if ($parent === null || $parent->user_id !== $userId) {
            throw new RedeemFailure(RedeemFailure::SPIN_NOT_FOUND, countsAsFailure: true);
        }

        $batch = DB::selectOne(
            'SELECT b.id AS batch_id, b.state, b.total_codes, b.reserve_size, b.campaign_id,
                    (b.redeemable_until IS NOT NULL AND b.redeemable_until <= clock_timestamp()) AS window_closed,
                    ca.name AS campaign_name, ca.game_type, ca.used_code_behavior, ca.title, ca.theme::text AS theme, ca.game_config::text AS game_config
             FROM batches b JOIN campaigns ca ON ca.id = b.campaign_id WHERE b.id = ? FOR SHARE', [$parent->batch_id]);
        // Los giros pendientes caducan al cerrar el lote o vencer su ventana (decisión de Luis, H6).
        if ($batch === null || $batch->state !== 'active' || $batch->window_closed) {
            throw new RedeemFailure(RedeemFailure::EXPIRED);
        }

        // El padre se bloquea: dos giros del mismo padre se serializan y el conteo no admite carreras.
        $granted = (int) DB::selectOne('SELECT spins_granted FROM redemptions WHERE id = ? FOR UPDATE', [$parentId])->spins_granted;
        // Carrera con la MISMA petición reintentada: la otra ganó el giro; esta debe ver el mismo resultado, no «sin giros».
        if (($byKey = $this->findByKey($userId, $key)) !== null) {
            return $this->replay($byKey, $parentId);
        }
        $used = (int) DB::selectOne('SELECT count(*) AS c FROM redemptions WHERE parent_redemption_id = ?', [$parentId])->c;
        if ($used >= $granted) {
            throw new RedeemFailure(RedeemFailure::NO_SPINS_LEFT);
        }

        // Cursor: serializa el consumo del lote y dice cuál es la siguiente casilla.
        $cursor = DB::selectOne('SELECT consumed FROM reserve_cursors WHERE batch_id = ? FOR UPDATE', [$batch->batch_id]);
        if ($cursor === null || (int) $cursor->consumed >= (int) $batch->reserve_size) {
            throw new RedeemFailure(RedeemFailure::RESERVE_EXHAUSTED);
        }
        $idx = (int) $cursor->consumed;

        $claimed = DB::update(
            'UPDATE reserve_slots SET consumed_at = now(), consumed_by = ? WHERE batch_id = ? AND idx = ? AND consumed_at IS NULL',
            [$userId, $batch->batch_id, $idx],
        );
        if ($claimed !== 1) {
            throw new \LogicException("La casilla de reserva $idx del lote {$batch->batch_id} no estaba libre con el cursor tomado.");
        }
        $slot = DB::selectOne("SELECT encode(prize_enc, 'hex') AS p FROM reserve_slots WHERE batch_id = ? AND idx = ?", [$batch->batch_id, $idx]);
        $prizeId = $this->vault->decryptReservePrize($batch->batch_id, (int) $batch->total_codes + $idx, hex2bin($slot->p));

        return $this->writer->write($batch, $userId, $key, $prizeId, ['reserve_idx' => $idx, 'parent' => $parentId, 'spin_no' => $used + 1]);
    }

    private function findByKey(string $userId, string $key): ?object
    {
        return DB::selectOne(
            'SELECT id, parent_redemption_id, response::text AS response FROM redemptions WHERE user_id = ? AND idempotency_key = ?',
            [$userId, $key],
        );
    }

    private function replay(object $existing, string $parentId): RedemptionResult
    {
        // La misma clave con OTRO padre (o con un canje de código) es un error de uso, no un reintento.
        if ($existing->parent_redemption_id !== $parentId) {
            throw new RedeemFailure(RedeemFailure::KEY_REUSE);
        }

        return new RedemptionResult($existing->response, $existing->id, replayed: true);
    }

    private function fail(string $userId, ?string $ip, string $reason): never
    {
        $this->throttle->recordFailure($userId, $ip);
        throw new RedeemFailure($reason);
    }
}
