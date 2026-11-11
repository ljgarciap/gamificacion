<?php

namespace App\Domain\Fulfillment;

use App\Domain\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Despacha los avisos pendientes de la outbox. Seguro con varios procesos a la vez: `FOR UPDATE SKIP LOCKED` hace que cada aviso lo tome
 * un solo proceso. Los fallos se reintentan con espera creciente (base × 2^(intentos-1), con tope) y, agotados los intentos, el aviso
 * queda en `failed` para revisión (no se pierde ni se borra).
 */
final class OutboxDispatcher
{
    public function __construct(private readonly PrizeFulfillment $fulfillment) {}

    /**
     * @param  int  $limit  máximo de avisos por corrida
     * @param  float|null  $maxSeconds  tope de tiempo por corrida (se revisa entre avisos; null = sin tope): sirve para seguir vaciando la cola
     *                                  mientras queden avisos vencidos sin pasarse del intervalo del planificador
     * @return array{sent: int, retry: int, failed: int}
     */
    public function dispatchDue(int $limit = 100, ?float $maxSeconds = null): array
    {
        $deadline = $maxSeconds === null ? null : microtime(true) + $maxSeconds;
        $max = Settings::int('outbox.max_attempts');
        $base = Settings::int('outbox.backoff_base_seconds');
        $cap = Settings::int('outbox.backoff_max_seconds');
        $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0];

        // UNA transacción por aviso (no una para todo el lote): entregar puede tardar hasta el tiempo máximo de la llamada al host, y mantener
        // abiertos los bloqueos de 100 avisos mientras un host lento responde frenaría hasta el reintento manual del panel.
        for ($i = 0; $i < $limit; $i++) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }
            $took = DB::transaction(function () use ($max, $base, $cap, &$stats): bool {
                $row = DB::selectOne(
                    "SELECT id, payload::text AS payload, attempts FROM prize_outbox
                     WHERE status = 'pending' AND next_attempt_at <= now()
                     ORDER BY next_attempt_at, id LIMIT 1 FOR UPDATE SKIP LOCKED");
                if ($row === null) {
                    return false;
                }
                $attempts = (int) $row->attempts + 1;
                try {
                    $this->fulfillment->deliver(json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR));
                    DB::update("UPDATE prize_outbox SET status = 'sent', sent_at = now(), attempts = ?, last_error = NULL WHERE id = ?", [$attempts, $row->id]);
                    $stats['sent']++;
                } catch (\Throwable $e) {
                    $error = mb_substr($e->getMessage(), 0, 500);
                    if ($e instanceof PermanentDeliveryFailure || $attempts >= $max) {
                        DB::update("UPDATE prize_outbox SET status = 'failed', attempts = ?, last_error = ? WHERE id = ?", [$attempts, $error, $row->id]);
                        $stats['failed']++;
                    } else {
                        $wait = (int) min($base * (2 ** ($attempts - 1)), $cap);
                        DB::update(
                            "UPDATE prize_outbox SET attempts = ?, last_error = ?, next_attempt_at = now() + (? * interval '1 second') WHERE id = ?",
                            [$attempts, $error, $wait, $row->id],
                        );
                        $stats['retry']++;
                    }
                }

                return true;
            });
            if (! $took) {
                break;
            }
        }

        return $stats;
    }
}
