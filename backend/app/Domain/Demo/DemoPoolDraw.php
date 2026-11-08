<?php

namespace App\Domain\Demo;

use App\Domain\Admin\SecretBox;
use Illuminate\Support\Facades\DB;

/**
 * Sorteo de los códigos que recibe quien «compra» (SPEC-006 §3.3). Elige con el CSPRNG del sistema operativo (`random_int`) entre los códigos
 * disponibles; el premio de cada código ya lo fijó el motor al comprometer el lote, así que la mezcla configurada es exacta. Un bloqueo
 * consultivo SERIALIZA los sorteos (la demo no necesita más caudal) y evita dos cosas: que dos compras reciban el mismo código y que
 * `SKIP LOCKED` sesgue la distribución. Debe llamarse DENTRO de una transacción: si algo falla después, la asignación se revierte.
 */
final class DemoPoolDraw
{
    private const LOCK_KEY = 6_006_001;

    /** @var \Closure(int, int): int */
    private \Closure $randomInt;

    /** @param (callable(int, int): int)|null $randomInt solo para pruebas (determinismo); por defecto el CSPRNG. */
    public function __construct(private readonly SecretBox $box = new SecretBox, ?callable $randomInt = null)
    {
        $this->randomInt = $randomInt !== null ? \Closure::fromCallable($randomInt) : random_int(...);
    }

    /** @return list<array{code: string, game: string}> */
    public function draw(string $userId, string $orderId, int $n): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('El sorteo debe correr dentro de una transacción.');
        }
        if ($n < 1) {
            return [];
        }
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
        $available = (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n;
        if ($available < $n) {
            throw new DemoPoolExhausted("Quedan $available código(s) y se pidieron $n.");
        }

        $out = [];
        for ($i = 0; $i < $n; $i++, $available--) {
            $k = ($this->randomInt)(0, $available - 1);
            $row = DB::selectOne("SELECT id, game, encode(code_enc, 'hex') AS enc, encode(code_hash, 'hex') AS hash FROM demo_pool WHERE state = 'available' ORDER BY id OFFSET ? LIMIT 1", [$k]);
            $updated = DB::update(
                "UPDATE demo_pool SET state = 'assigned', assigned_to = ?, assigned_at = clock_timestamp(), order_id = ? WHERE id = ? AND state = 'available'",
                [$userId, $orderId, $row->id],
            );
            if ($updated !== 1) {
                throw new \LogicException('El código sorteado dejó de estar disponible dentro del bloqueo.'); // no debería pasar
            }
            $out[] = ['code' => $this->box->open(hex2bin($row->enc), DemoSeeder::sealContext($row->hash)), 'game' => $row->game];
        }

        return $out;
    }
}
