<?php

namespace App\Domain\Fulfillment;

use App\Domain\Hosts\HostClient;
use App\Domain\Hosts\HostUnavailable;

/**
 * Entrega el aviso de acreditación a la app host (SPEC-003 RF-50). `2xx` = entregado. `408`, `425`, `429`, `5xx` y los fallos de red se reintentan
 * (la outbox espera cada vez más). Cualquier otro estado (4xx, o 3xx porque no se siguen redirecciones) es un rechazo permanente: reintentar
 * no lo arregla y la outbox lo deja `failed` para revisión. Los mensajes de error llevan el estado, nunca el cuerpo de la respuesta.
 */
final class HttpPrizeFulfillment implements PrizeFulfillment
{
    public function __construct(private readonly HostClient $client) {}

    public function deliver(array $payload): void
    {
        $userId = (string) ($payload['user_id'] ?? '');
        $eventId = (string) ($payload['redemption_id'] ?? '');
        if ($userId === '' || $eventId === '') {
            throw new PermanentDeliveryFailure('el aviso no tiene usuario o identificador de canje');
        }

        $r = $this->client->post($userId, $eventId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $status = $r['status'];
        if ($status >= 200 && $status < 300) {
            return;
        }
        if (in_array($status, [408, 425, 429], true) || $status >= 500) {
            throw new HostUnavailable("el host respondió HTTP $status (se reintenta)");
        }
        throw new PermanentDeliveryFailure("el host rechazó el aviso con HTTP $status");
    }
}
