<?php

namespace Tests\Feature\Batches;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Crypto\PrizeVault;
use App\Engine\Commitment;
use App\Engine\Pool;
use App\Engine\PrizeAssigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/**
 * Reserva del giro extra en la base (SPEC-003 P6b, docs/seguridad-p6.md). Se ataca con SQL directo, saltándose el servicio: cada
 * intento debe ser rechazado por la razón correcta.
 */
final class ReserveSchemaTest extends DbTestCase
{
    private Fixtures $f;

    private string $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
        // Ruleta con un premio de giro extra: 8 segmentos que usan los premios del pool.
        $this->campaign = $this->f->campaign('roulette');
        DB::table('prizes')->insert(['campaign_id' => $this->campaign, 'prize_id' => 'extra', 'type' => 'extraSpin', 'label' => 'Gira de nuevo', 'amount' => null, 'meta' => json_encode(['extra_spins' => 1])]);
        DB::table('campaigns')->where('id', $this->campaign)->update(['game_config' => json_encode(['segments' => ['p50k', 'none', 'p10k', 'extra', 'none', 'p10k', 'none', 'p50k']])]);
    }

    /** 6 none + 3 p10k + 2 extra = 11 posiciones: 8 códigos + 3 de reserva. */
    private function pool(): array
    {
        return [['prize_id' => 'none', 'quantity' => 6], ['prize_id' => 'p10k', 'quantity' => 3], ['prize_id' => 'extra', 'quantity' => 2]];
    }

    private function draft(int $reserve = 3, ?array $pool = null): string
    {
        return $this->f->lifecycle()->createDraft($this->campaign, 'Con reserva', $pool ?? $this->pool(), Fixtures::ACTOR, null, $reserve);
    }

    private function committed(int $reserve = 3): string
    {
        $id = $this->draft($reserve);
        $this->f->addCodes($id, 11 - $reserve);
        $this->f->lifecycle()->commit($id, Fixtures::ACTOR);

        return $id;
    }

    private function active(int $reserve = 3): string
    {
        $id = $this->committed($reserve);
        $this->f->activate($id);

        return $id;
    }

    /** Reclama un código y registra su redención con `$granted` giros concedidos (como lo haría RedeemService). */
    private function parent(string $batch, string $user, int $granted = 1): string
    {
        $code = DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL ORDER BY idx LIMIT 1', [$batch])->id;
        DB::update('UPDATE codes SET redeemed_at = now(), redeemed_by = ? WHERE id = ?', [$user, $code]);
        $id = (string) Str::uuid();
        DB::insert(
            "INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response, spins_granted) VALUES (?, ?, ?, ?, ?, 'extra', '{}'::json, ?)",
            [$id, $code, $batch, $user, 'padre-'.Str::random(10), $granted],
        );

        return $id;
    }

    private function consume(string $batch, int $idx, string $user): int
    {
        return DB::update('UPDATE reserve_slots SET consumed_at = now(), consumed_by = ? WHERE batch_id = ? AND idx = ?', [$user, $batch, $idx]);
    }

    private function child(string $batch, string $parent, int $idx, int $spinNo, string $user): bool
    {
        return DB::insert(
            "INSERT INTO redemptions (id, reserve_idx, parent_redemption_id, spin_no, batch_id, user_id, idempotency_key, prize_id, response) VALUES (?, ?, ?, ?, ?, ?, ?, 'none', '{}'::json)",
            [(string) Str::uuid(), $idx, $parent, $spinNo, $batch, $user, 'hijo-'.Str::random(10)],
        );
    }

    // --- Ciclo de vida con reserva --------------------------------------------------------------------------------

    public function test_un_borrador_con_reserva_crea_sus_casillas_y_cursor_y_los_codigos_son_n(): void
    {
        $id = $this->draft();
        $this->assertSame(8, (int) DB::table('batches')->where('id', $id)->value('total_codes'));
        $this->assertSame(3, (int) DB::table('batches')->where('id', $id)->value('reserve_size'));
        $this->assertSame(3, DB::table('reserve_slots')->where('batch_id', $id)->count());
        $this->assertSame(0, (int) DB::table('reserve_cursors')->where('batch_id', $id)->value('consumed'));
    }

    public function test_con_reserva_el_compromiso_es_v2_y_con_reserva_cero_sigue_siendo_v1(): void
    {
        $with = $this->committed(3);
        $row = $this->f->batchRow($with);
        $pool = Pool::fromEntries($this->pool());
        $hashes = array_map(fn ($r) => hex2bin($r->h), DB::select("SELECT encode(code_hash,'hex') AS h FROM codes WHERE batch_id = ? ORDER BY idx", [$with]));
        $digest = Commitment::codesDigest($hashes);
        $seed = (new PrizeVault)->decryptSeed($with, hex2bin($row->seed_enc));
        $chain = hex2bin($row->chain_hash);
        $this->assertSame(
            $row->commitment,
            bin2hex(Commitment::commitV2($with, $pool, 3, $digest, $chain, (int) $row->beacon_round, $seed)),
        );
        $this->assertNotSame($row->commitment, bin2hex(Commitment::commit($with, $pool, $digest, $chain, (int) $row->beacon_round, $seed)));

        // R = 0: exactamente el compromiso de siempre.
        $plain = $this->f->committed(Fixtures::pool());
        $r0 = $this->f->batchRow($plain);
        $h0 = array_map(fn ($r) => hex2bin($r->h), DB::select("SELECT encode(code_hash,'hex') AS h FROM codes WHERE batch_id = ? ORDER BY idx", [$plain]));
        $s0 = (new PrizeVault)->decryptSeed($plain, hex2bin($r0->seed_enc));
        $this->assertSame($r0->commitment, bin2hex(Commitment::commit(
            $plain, Pool::fromEntries(Fixtures::pool()), Commitment::codesDigest($h0), hex2bin($r0->chain_hash), (int) $r0->beacon_round, $s0,
        )));
        $this->assertSame(0, DB::table('reserve_slots')->where('batch_id', $plain)->count());
    }

    public function test_activar_cifra_cada_casilla_y_revelar_la_verifica(): void
    {
        $id = $this->active();
        $row = $this->f->batchRow($id);
        $this->assertSame(0, DB::table('reserve_slots')->where('batch_id', $id)->whereNull('prize_enc')->count());

        $this->f->lifecycle()->close($id, Fixtures::ACTOR);
        $this->f->lifecycle()->reveal($id, Fixtures::ACTOR);

        $seed = hex2bin((string) $this->f->batchRow($id)->seed_revealed);
        $deck = PrizeAssigner::assignDeck(Pool::fromEntries($this->pool()), 3, $seed, hex2bin($row->beacon_value), $id);
        $vault = new PrizeVault;
        foreach (DB::select("SELECT idx, encode(prize_enc,'hex') AS p FROM reserve_slots WHERE batch_id = ? ORDER BY idx", [$id]) as $s) {
            $this->assertSame($deck['reserve'][$s->idx], $vault->decryptReservePrize($id, 8 + $s->idx, hex2bin($s->p)));
        }
    }

    public function test_revelar_detecta_una_casilla_consumida_fuera_del_servicio(): void
    {
        $id = $this->active();
        DB::table('reserve_slots')->where('batch_id', $id)->where('idx', 0)->update(['consumed_at' => now(), 'consumed_by' => 'alguien']);
        $this->f->lifecycle()->close($id, Fixtures::ACTOR);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('la reserva');
        $this->f->lifecycle()->reveal($id, Fixtures::ACTOR);
    }

    public function test_el_cifrado_de_una_casilla_no_autentica_como_un_codigo_aunque_coincida_la_posicion(): void
    {
        $vault = new PrizeVault;
        $id = (string) Str::uuid();
        $sealed = $vault->encryptReservePrize($id, 8, 'p10k');
        $this->assertSame('p10k', $vault->decryptReservePrize($id, 8, $sealed));
        // Misma posición global, contexto de otro tipo: quien intercambie textos cifrados en la base no lo logra.
        $this->expectException(\Throwable::class);
        $vault->decryptPrize($id, 8, $sealed);
    }

    // --- Validación al crear/comprometer --------------------------------------------------------------------------

    public function test_una_reserva_que_no_cubre_el_peor_caso_se_rechaza(): void
    {
        // 2 premios «giro extra» con 1 giro cada uno piden 2 casillas: con R = 1 no alcanza.
        $this->expectException(InvalidBatch::class);
        $this->expectExceptionMessage('no cubre el peor caso');
        $this->draft(1);
    }

    public function test_un_premio_de_giro_extra_con_numero_de_giros_invalido_guardado_antes_se_rechaza_al_crear_el_lote(): void
    {
        // Datos viejos o escritos sin pasar por la API: -40 giros compensaban 50 y la cuenta de la reserva «pasaba».
        DB::table('prizes')->where('campaign_id', $this->campaign)->where('prize_id', 'extra')->update(['meta' => json_encode(['extra_spins' => -40])]);
        $this->expectException(InvalidBatch::class);
        $this->expectExceptionMessage('número válido de giros');
        $this->draft(3);
    }

    public function test_con_reserva_la_foto_de_los_premios_trae_los_giros_y_sin_reserva_no(): void
    {
        $with = $this->committed(3);
        $snapshot = fn (string $batch) => json_decode((string) DB::table('audit_log')->where('action', 'batch.committed')->where('subject_id', $batch)->value('data'), true)['prizes'];
        $extra = collect($snapshot($with))->firstWhere('prize_id', 'extra');
        $this->assertSame(1, $extra['spins']);
        $this->assertArrayNotHasKey('spins', collect($snapshot($with))->firstWhere('prize_id', 'none'));

        // Sin reserva (lote de siempre) la bitácora es la de siempre: ni `spins` en la foto ni `spins_granted` al canjear.
        $plain = $this->committed(0);
        $this->assertArrayNotHasKey('spins', collect($snapshot($plain))->firstWhere('prize_id', 'extra'));
    }

    public function test_la_reserva_debe_dejar_al_menos_un_codigo(): void
    {
        foreach ([-1, 11, 12] as $bad) {
            try {
                $this->draft($bad);
                $this->fail("la reserva $bad debió rechazarse");
            } catch (InvalidBatch) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_sin_reserva_se_conserva_el_comportamiento_anterior_aunque_haya_giros_extra_en_el_pool(): void
    {
        $id = $this->draft(0);
        $this->assertSame(11, (int) DB::table('batches')->where('id', $id)->value('total_codes'));
        $this->assertSame(0, DB::table('reserve_slots')->where('batch_id', $id)->count());
    }

    // --- Invariantes de la base -----------------------------------------------------------------------------------

    public function test_la_reserva_se_congela_al_comprometer(): void
    {
        $id = $this->committed();
        $this->assertDbRejects(fn () => DB::update('UPDATE batches SET reserve_size = 4 WHERE id = ?', [$id]), 'reserva de un lote comprometido');
    }

    public function test_no_se_compromete_con_la_reserva_incompleta(): void
    {
        $id = $this->draft();
        $this->f->addCodes($id, 8);
        DB::delete('DELETE FROM reserve_slots WHERE batch_id = ? AND idx = 2', [$id]);
        $this->assertDbRejects(fn () => DB::update("UPDATE batches SET state = 'committed' WHERE id = ?", [$id]), 'reserva completa');
    }

    public function test_el_pool_debe_sumar_codigos_mas_reserva(): void
    {
        $id = $this->draft();
        $this->f->addCodes($id, 8);
        DB::update('UPDATE batches SET reserve_size = 2 WHERE id = ?', [$id]); // un borrador sí se puede editar
        $this->assertDbRejects(fn () => DB::update("UPDATE batches SET state = 'committed' WHERE id = ?", [$id]), 'códigos + reserva');
    }

    public function test_el_premio_de_una_casilla_es_de_escritura_unica_y_solo_al_activar(): void
    {
        $active = $this->active();
        $this->assertDbRejects(fn () => DB::update("UPDATE reserve_slots SET prize_enc = decode('00', 'hex') WHERE batch_id = ? AND idx = 0", [$active]), 'inmutable');
        $draft = $this->draft();
        $this->assertDbRejects(fn () => DB::update("UPDATE reserve_slots SET prize_enc = decode('00', 'hex') WHERE batch_id = ? AND idx = 0", [$draft]), 'solo se asigna al activar');
    }

    public function test_las_casillas_se_consumen_en_orden_estricto(): void
    {
        $id = $this->active();
        $this->assertDbRejects(fn () => $this->consume($id, 1, 'host-a:ana'), 'en orden');
        $this->assertSame(1, $this->consume($id, 0, 'host-a:ana'));
        $this->assertSame(1, (int) DB::table('reserve_cursors')->where('batch_id', $id)->value('consumed'));
        $this->assertDbRejects(fn () => $this->consume($id, 2, 'host-a:ana'), 'en orden');
        $this->assertSame(1, $this->consume($id, 1, 'host-a:beto'));
    }

    public function test_una_casilla_no_se_consume_dos_veces_ni_se_libera(): void
    {
        $id = $this->active();
        $this->consume($id, 0, 'host-a:ana');
        $this->assertDbRejects(fn () => $this->consume($id, 0, 'host-a:beto'), 'ya fue consumida');
        $this->assertDbRejects(fn () => DB::update('UPDATE reserve_slots SET consumed_at = NULL, consumed_by = NULL WHERE batch_id = ? AND idx = 0', [$id]), 'ya fue consumida');
    }

    public function test_solo_se_consume_con_el_lote_activo(): void
    {
        $this->assertDbRejects(fn () => $this->consume($this->committed(), 0, 'host-a:ana'), 'solo se consumen casillas de lotes activos');
        $closed = $this->active();
        $this->f->lifecycle()->close($closed, Fixtures::ACTOR);
        $this->assertDbRejects(fn () => $this->consume($closed, 0, 'host-a:ana'), 'solo se consumen casillas de lotes activos');
    }

    public function test_el_cursor_solo_avanza_de_uno_en_uno_y_no_pasa_de_la_reserva(): void
    {
        $id = $this->active();
        $this->assertDbRejects(fn () => DB::update('UPDATE reserve_cursors SET consumed = 2 WHERE batch_id = ?', [$id]), 'de uno en uno');
        $this->assertDbRejects(fn () => DB::update('UPDATE reserve_cursors SET consumed = consumed - 1 WHERE batch_id = ?', [$id]), 'de uno en uno');
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM reserve_cursors WHERE batch_id = ?', [$id]), 'no se borra');
    }

    public function test_no_se_borran_ni_se_agregan_casillas_de_un_lote_comprometido(): void
    {
        $id = $this->committed();
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM reserve_slots WHERE batch_id = ?', [$id]), 'no se borran casillas');
        $this->assertDbRejects(fn () => DB::insert('INSERT INTO reserve_slots (batch_id, idx) VALUES (?, 9)', [$id]), 'solo se agregan casillas');
    }

    // --- Redenciones hijas ----------------------------------------------------------------------------------------

    public function test_un_giro_hijo_valido(): void
    {
        $id = $this->active();
        $parent = $this->parent($id, 'host-a:ana');
        $this->consume($id, 0, 'host-a:ana');
        $this->assertTrue($this->child($id, $parent, 0, 1, 'host-a:ana'));
    }

    public function test_un_giro_hijo_no_excede_los_giros_concedidos(): void
    {
        $id = $this->active();
        $parent = $this->parent($id, 'host-a:ana', 1);
        $this->consume($id, 0, 'host-a:ana');
        $this->child($id, $parent, 0, 1, 'host-a:ana');
        $this->consume($id, 1, 'host-a:ana');
        $this->assertDbRejects(fn () => $this->child($id, $parent, 1, 2, 'host-a:ana'), 'solo concede 1 giros');
    }

    public function test_un_padre_sin_giros_concedidos_no_tiene_hijos(): void
    {
        $id = $this->active();
        $parent = $this->parent($id, 'host-a:ana', 0);
        $this->consume($id, 0, 'host-a:ana');
        $this->assertDbRejects(fn () => $this->child($id, $parent, 0, 1, 'host-a:ana'), 'solo concede 0 giros');
    }

    public function test_el_padre_debe_ser_del_mismo_usuario_y_lote(): void
    {
        $id = $this->active();
        $parent = $this->parent($id, 'host-a:ana');
        $this->consume($id, 0, 'host-a:beto');
        $this->assertDbRejects(fn () => $this->child($id, $parent, 0, 1, 'host-a:beto'), 'mismo usuario y lote');
        $other = $this->active();
        $this->assertDbRejects(fn () => $this->child($other, $parent, 0, 1, 'host-a:ana'), 'casilla de reserva consumida');
    }

    public function test_la_redencion_hija_exige_haber_consumido_la_casilla_ese_usuario(): void
    {
        $id = $this->active();
        $parent = $this->parent($id, 'host-a:ana');
        $this->assertDbRejects(fn () => $this->child($id, $parent, 0, 1, 'host-a:ana'), 'casilla de reserva consumida');
        $this->consume($id, 0, 'host-a:beto');
        $this->assertDbRejects(fn () => $this->child($id, $parent, 0, 1, 'host-a:ana'), 'casilla de reserva consumida');
    }

    public function test_una_casilla_o_un_numero_de_giro_no_se_repiten_y_van_en_orden(): void
    {
        $id = $this->active();
        $parent = $this->parent($id, 'host-a:ana', 3);
        $this->consume($id, 0, 'host-a:ana');
        $this->assertDbRejects(fn () => $this->child($id, $parent, 0, 2, 'host-a:ana'), 'en orden');
        $this->child($id, $parent, 0, 1, 'host-a:ana');
        $this->assertDbRejects(fn () => $this->child($id, $parent, 0, 1, 'host-a:ana'), 'numeran en orden');
    }

    public function test_una_redencion_tiene_codigo_o_casilla_nunca_ambos_ni_ninguno(): void
    {
        $id = $this->active();
        $parent = $this->parent($id, 'host-a:ana');
        $this->assertDbRejects(fn () => DB::insert(
            "INSERT INTO redemptions (id, batch_id, user_id, idempotency_key, prize_id, response) VALUES (?, ?, 'u', 'clave-sin-fuente', 'none', '{}'::json)",
            [(string) Str::uuid(), $id],
        ), 'casilla de reserva consumida'); // el trigger la rechaza antes que el CHECK
        // Con ambas fuentes y todo lo demás en regla (código reclamado y casilla consumida por el mismo usuario), la que rechaza es la restricción.
        $code = (int) DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL LIMIT 1', [$id])->id;
        DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'host-a:ana' WHERE id = ?", [$code]);
        $this->consume($id, 0, 'host-a:ana');
        $this->assertDbRejects(fn () => DB::insert(
            "INSERT INTO redemptions (id, code_id, reserve_idx, parent_redemption_id, spin_no, batch_id, user_id, idempotency_key, prize_id, response) VALUES (?, ?, 0, ?, 1, ?, 'host-a:ana', 'clave-ambas-cosas', 'none', '{}'::json)",
            [(string) Str::uuid(), $code, $parent, $id],
        ), 'redemptions_one_source');
    }

    public function test_una_redencion_hija_sin_padre_o_con_padre_y_sin_casilla_no_existe(): void
    {
        $id = $this->active();
        $parent = $this->parent($id, 'host-a:ana');
        $code = (int) DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL LIMIT 1', [$id])->id;
        DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'host-a:ana' WHERE id = ?", [$code]);
        $this->assertDbRejects(fn () => DB::insert(
            "INSERT INTO redemptions (id, code_id, parent_redemption_id, spin_no, batch_id, user_id, idempotency_key, prize_id, response) VALUES (?, ?, ?, 1, ?, 'host-a:ana', 'clave-forma-mala', 'none', '{}'::json)",
            [(string) Str::uuid(), $code, $parent, $id],
        ), 'redemptions_child_shape');
    }
}
