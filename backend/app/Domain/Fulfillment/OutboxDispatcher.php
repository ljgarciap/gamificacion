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

    /** @return array{sent: int, retry: int, failed: int} */
    public function dispatchDue(int $limit = 100): array
    {
        $max = Settings::int('outbox.max_attempts');
        $base = Settings::int('outbox.backoff_base_seconds');
        $cap = Settings::int('outbox.backoff_max_seconds');
        $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0];

        DB::transaction(function () use ($limit, $max, $base, $cap, &$stats): void {
            $rows = DB::select(
                "SELECT id, payload::text AS payload, attempts FROM prize_outbox
                 WHERE status = 'pending' AND next_attempt_at <= now()
                 ORDER BY next_attempt_at, id LIMIT ? FOR UPDATE SKIP LOCKED", [$limit]);

            foreach ($rows as $row) {
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
            }
        });

        return $stats;
    }
}
