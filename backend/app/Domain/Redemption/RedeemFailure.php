<?php

namespace App\Domain\Redemption;

/**
 * Fallo esperado al redimir. `reason` coincide con `FailureReason` del cliente Flutter (más idempotencyKeyReuse, que es un error
 * de uso de la API). Distingue "no existe" de "ya usado" solo cuando hace falta para la experiencia (ver SPEC-001 RF-01).
 */
class RedeemFailure extends \RuntimeException
{
    public const INVALID = 'invalidCode';

    public const ALREADY_USED = 'alreadyUsed';

    public const EXPIRED = 'expired';

    public const CAMPAIGN_INACTIVE = 'campaignInactive';

    public const RATE_LIMITED = 'rateLimited';

    public const KEY_REUSE = 'idempotencyKeyReuse';

    /** Giros hijos (SPEC-003 P6c). */
    public const SPIN_NOT_FOUND = 'spinNotFound';

    public const NO_SPINS_LEFT = 'noSpinsLeft';

    public const RESERVE_EXHAUSTED = 'spinReserveExhausted';

    /**
     * @param  bool  $countsAsFailure  el servicio lo cuenta contra el límite de intentos DESPUÉS de terminar la transacción
     *                                 (contarlo dentro se perdería cuando la transacción se revierte: la caché de base de datos
     *                                 comparte conexión con ella)
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?int $retryAfter = null,
        public readonly bool $countsAsFailure = false,
    ) {
        parent::__construct($reason);
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            self::INVALID, self::KEY_REUSE => 422,
            self::ALREADY_USED, self::NO_SPINS_LEFT, self::RESERVE_EXHAUSTED => 409,
            self::SPIN_NOT_FOUND => 404,
            self::EXPIRED => 410,
            self::CAMPAIGN_INACTIVE => 403,
            self::RATE_LIMITED => 429,
            default => 400,
        };
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::INVALID => 'El código no es válido.',
            self::ALREADY_USED => 'Este código ya fue utilizado.',
            self::EXPIRED => 'Este código está vencido.',
            self::CAMPAIGN_INACTIVE => 'Esta promoción no está activa.',
            self::RATE_LIMITED => 'Demasiados intentos. Intenta de nuevo más tarde.',
            self::KEY_REUSE => 'La Idempotency-Key ya se usó con otro código.',
            self::SPIN_NOT_FOUND => 'No hay un giro para jugar con esa redención.',
            self::NO_SPINS_LEFT => 'Esa redención no tiene más giros por jugar.',
            self::RESERVE_EXHAUSTED => 'Los giros extra de esta promoción se agotaron.',
            default => 'Error.',
        };
    }
}
