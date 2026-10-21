<?php

namespace App\Domain\Redemption;

use Illuminate\Support\Facades\DB;

/**
 * Giros que una redención (y los giros que salieron de ella) todavía tienen por jugar. Sirve para recuperarlos cuando el jugador vuelve a
 * abrir la app con un código ya jugado: el cliente solo recuerda el código, no cuántos giros quedaban. Es lo único del resultado que
 * cambia con el tiempo, por eso NO va en la respuesta guardada del canje (que se repite byte a byte) sino solo al volver a ver un resultado
 * anterior. El llamador comprueba que el lote sigue activo: al cerrarlo los giros caducan.
 */
final class PendingSpins
{
    /** @return list<array{redemptionId: string, remaining: int, played: int}> */
    public static function of(string $redemptionId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE tree AS (
                 SELECT id, spins_granted, created_at FROM redemptions WHERE id = ?
                 UNION ALL
                 SELECT r.id, r.spins_granted, r.created_at FROM redemptions r JOIN tree t ON r.parent_redemption_id = t.id
             )
             SELECT t.id, t.spins_granted, (SELECT count(*) FROM redemptions c WHERE c.parent_redemption_id = t.id) AS played
             FROM tree t ORDER BY t.created_at, t.id',
            [$redemptionId],
        );

        $out = [];
        foreach ($rows as $r) {
            $remaining = (int) $r->spins_granted - (int) $r->played;
            if ($remaining > 0) {
                $out[] = ['redemptionId' => $r->id, 'remaining' => $remaining, 'played' => (int) $r->played];
            }
        }

        return $out;
    }
}
