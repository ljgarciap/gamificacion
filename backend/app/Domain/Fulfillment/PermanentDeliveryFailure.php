<?php

namespace App\Domain\Fulfillment;

/** El host rechazó el aviso de forma que reintentar no lo arregla (p. ej. 400/401/403/404 o una redirección). La outbox lo marca `failed` sin más intentos. */
final class PermanentDeliveryFailure extends \RuntimeException {}
