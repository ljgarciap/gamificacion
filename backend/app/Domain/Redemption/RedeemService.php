<?php

namespace App\Domain\Redemption;

use App\Domain\Audit\AuditLog;
use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\InvalidCodeFormat;
use App\Domain\Crypto\CodeHasher;
use App\Domain\Crypto\PrizeVault;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Redención de un código (SPEC-002 §5, RF-24).
 *
 * Aquí NO hay sorteo: el premio ya está asignado (cifrado) desde que se activó el lote. Redimir es reclamar el código de forma
 * ATÓMICA, descifrar su premio, construir lo que se muestra y registrarlo, todo en UNA transacción:
 *
 *   UPDATE codes SET redeemed_at = now(), redeemed_by = :usuario WHERE id = :id AND redeemed_at IS NULL
 *
 * Con N peticiones simultáneas del mismo código, PostgreSQL serializa ese UPDATE por la fila: exactamente UNA lo gana (CA-25).
 * Los reintentos con la misma Idempotency-Key devuelven la misma respuesta sin volver a jugar ni a acreditar (CA-26).
 */
final class RedeemService
{
    public function __construct(
        private readonly PrizeVault $vault,
        private readonly CodeHasher $hasher,
        private readonly AuditLog $audit,
        private readonly RedeemThrottle $throttle,
        private readonly Presentation $presentation,
    ) {}

    /**
     * @throws RedeemFailure
     */
    public function redeem(string $userId, string $idempotencyKey, string $rawCode, ?string $ip = null): RedemptionResult
    {
        $this->throttle->assertAllowed($userId, $ip);

        try {
            if (strlen($rawCode) > 128) {
                throw new InvalidCodeFormat(InvalidCodeFormat::MALFORMED, 'demasiado largo');
            }
            $hash = bin2hex($this->hasher->hash(CodeFormat::normalize($rawCode)));
        } catch (InvalidCodeFormat) {
            $this->fail($userId, $ip, RedeemFailure::INVALID); // un error de tipeo no toca la base de datos
        }

        // Reintento del mismo usuario con la misma clave: se repite la respuesta, no se vuelve a jugar.
        if (($existing = $this->findByKey($userId, $idempotencyKey)) !== null) {
            return $this->replay($existing, $hash);
        }

        $row = $this->lookup($hash);
        if ($row === null || $row->state === 'draft') {
            $this->fail($userId, $ip, RedeemFailure::INVALID);
        }
        $this->assertRedeemable($row);

        try {
            $result = DB::transaction(fn (): RedemptionResult => $this->claim($row, $userId, $idempotencyKey, $hash));
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
                    return $this->replay($other, $hash);
                }
            }
            // El lote se cerró justo mientras se redimía.
            if (str_contains($e->getMessage(), 'solo se redimen códigos de lotes activos')) {
                throw new RedeemFailure(RedeemFailure::EXPIRED);
            }
            throw $e;
        }

        $this->throttle->recordSuccess($userId);

        return $result;
    }

    // --- Reclamo atómico -----------------------------------------------------------------------------------------------

    private function claim(object $row, string $userId, string $key, string $hash): RedemptionResult
    {
        // Bloqueo COMPARTIDO sobre el lote: los canjes no se estorban entre sí, pero cerrar el lote (que lo toma EXCLUSIVO) espera a que
        // terminen los que están en curso, y los nuevos ven el lote ya cerrado. Así el cierre es una barrera nítida: ninguna redención
        // se confirma "después" del cierre y el conteo de redimidos que se registra al cerrar es exacto. El estado se relee AQUÍ,
        // con el bloqueo tomado, porque el que se leyó antes pudo quedar viejo.
        $batch = DB::selectOne(
            'SELECT state, (redeemable_until IS NOT NULL AND redeemable_until <= clock_timestamp()) AS window_closed
             FROM batches WHERE id = ? FOR SHARE', [$row->batch_id]);
        if ($batch === null || $batch->state !== 'active' || $batch->window_closed) {
            throw new RedeemFailure($batch?->state === 'committed' ? RedeemFailure::CAMPAIGN_INACTIVE : RedeemFailure::EXPIRED);
        }

        $claimed = DB::update(
            'UPDATE codes SET redeemed_at = now(), redeemed_by = ? WHERE id = ? AND redeemed_at IS NULL',
            [$userId, $row->code_id],
        );
        if ($claimed === 0) {
            return $this->taken($row, $userId, $key, $hash);
        }

        $prizeId = $this->vault->decryptPrize($row->batch_id, (int) $row->idx, hex2bin($row->prize_enc));

        return (new RedemptionWriter($this->audit, $this->presentation))->write($row, $userId, $key, $prizeId, ['code_id' => $row->code_id]);
    }

    /** El código ya estaba reclamado. Según quién y la regla de la campaña: repetir, o rechazar. */
    private function taken(object $row, string $userId, string $key, string $hash): RedemptionResult
    {
        // Carrera con la MISMA petición reintentada: la otra ganó el reclamo; esta debe ver el mismo resultado.
        if (($byKey = $this->findByKey($userId, $key)) !== null) {
            return $this->replay($byKey, $hash);
        }

        $mine = DB::selectOne(
            'SELECT id, user_id, response::text AS response, created_at FROM redemptions WHERE code_id = ?', [$row->code_id],
        );
        if ($mine !== null && $mine->user_id === $userId && $row->used_code_behavior === 'showPrevious') {
            return $this->previous($mine);
        }

        // Nunca se revela el resultado de OTRA persona. Si el código es de otro, alguien está probando códigos ajenos: cuenta
        // (anotado por quien llama, fuera de la transacción).
        throw new RedeemFailure(RedeemFailure::ALREADY_USED, countsAsFailure: $mine === null || $mine->user_id !== $userId);
    }

    // --- Búsquedas ----------------------------------------------------------------------------------------------------------

    private function lookup(string $hashHex): ?object
    {
        return DB::selectOne(
            "SELECT c.id AS code_id, c.batch_id, c.idx, encode(c.prize_enc, 'hex') AS prize_enc,
                    b.state, b.campaign_id, (b.redeemable_until IS NOT NULL AND b.redeemable_until <= now()) AS window_closed,
                    ca.name AS campaign_name, ca.game_type, ca.used_code_behavior, ca.title,
                    ca.theme::text AS theme, ca.game_config::text AS game_config
             FROM codes c JOIN batches b ON b.id = c.batch_id JOIN campaigns ca ON ca.id = b.campaign_id
             WHERE c.code_hash = decode(?, 'hex')", [$hashHex]);
    }

    private function findByKey(string $userId, string $key): ?object
    {
        return DB::selectOne(
            "SELECT r.id, r.user_id, r.response::text AS response, encode(c.code_hash, 'hex') AS code_hash
             FROM redemptions r LEFT JOIN codes c ON c.id = r.code_id WHERE r.user_id = ? AND r.idempotency_key = ?",
            [$userId, $key]);
    }

    private function assertRedeemable(object $row): void
    {
        if ($row->state === 'committed') {
            throw new RedeemFailure(RedeemFailure::CAMPAIGN_INACTIVE);
        }
        if ($row->state !== 'active' || $row->window_closed) {
            throw new RedeemFailure(RedeemFailure::EXPIRED); // closed, revealed o ventana de redención vencida
        }
    }

    private function replay(object $existing, string $hash): RedemptionResult
    {
        // La misma clave con OTRO código es un error de uso, no un reintento.
        if ($existing->code_hash !== $hash) {
            throw new RedeemFailure(RedeemFailure::KEY_REUSE);
        }

        return new RedemptionResult($existing->response, $existing->id, replayed: true);
    }

    /** Resultado previo de un código ya jugado por este mismo usuario (regla `showPrevious`). */
    private function previous(object $redemption): RedemptionResult
    {
        $body = json_decode($redemption->response); // como objetos: {} sigue siendo {}
        $body->alreadyPlayed = true;
        $played = Carbon::parse($redemption->created_at)->utc();
        $body->playedAt = $played->format('Y-m-d\TH:i:s.u\Z');

        return new RedemptionResult(
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $redemption->id,
            replayed: true,
        );
    }

    private function fail(string $userId, ?string $ip, string $reason): never
    {
        $this->throttle->recordFailure($userId, $ip);
        throw new RedeemFailure($reason);
    }
}
