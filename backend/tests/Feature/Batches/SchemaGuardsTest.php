<?php

namespace Tests\Feature\Batches;

use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/**
 * CA-28 — La inmutabilidad la hace cumplir LA BASE DE DATOS, no solo la aplicación.
 *
 * Estas pruebas atacan las tablas con SQL directo, saltándose por completo BatchLifecycle: representan un bug de la
 * aplicación, un script de mantenimiento o alguien con acceso a la base intentando cambiar un lote ya comprometido.
 * Cada intento debe ser rechazado por PostgreSQL por la razón correcta (se comprueba el mensaje, no solo que falle).
 */
final class SchemaGuardsTest extends DbTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
    }

    // --- Lote: borrador editable, comprometido congelado -----------------------------------------------------

    public function test_un_borrador_se_puede_editar_y_borrar(): void
    {
        $id = $this->f->draft();
        $this->assertSame(1, DB::update("UPDATE batches SET name = 'Otro nombre' WHERE id = ?", [$id]));
        $this->assertSame(1, DB::delete('DELETE FROM batches WHERE id = ?', [$id]));
    }

    public function test_no_se_agregan_codigos_a_un_lote_comprometido(): void
    {
        $id = $this->f->committed();
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO codes (batch_id, idx, code_hash) VALUES (?, 0, decode(repeat('ab', 32), 'hex'))", [$id]),
            'solo se agregan códigos a un lote en borrador',
        );
    }

    public function test_cada_columna_comprometida_queda_congelada(): void
    {
        $id = $this->f->committed();
        $attempts = [
            'name' => ["name = 'otro'", []],
            'pool' => ['pool = \'[{"prize_id":"none","quantity":99}]\'::jsonb', []],
            'total_codes' => ['total_codes = 99', []],
            'redeemable_until' => ['redeemable_until = now()', []],
            'commitment' => ["commitment = decode(repeat('00', 32), 'hex')", []],
            'codes_digest' => ["codes_digest = decode(repeat('00', 32), 'hex')", []],
            'chain_hash' => ["chain_hash = decode('00', 'hex')", []],
            'beacon_round' => ['beacon_round = 1', []],
            'seed_enc' => ["seed_enc = decode('00', 'hex')", []],
            'created_by' => ["created_by = 'intruso'", []],
        ];
        foreach ($attempts as $column => [$set, $bind]) {
            $this->assertDbRejects(
                fn () => DB::update("UPDATE batches SET $set WHERE id = ?", [...$bind, $id]),
                'su definición es inmutable',
            );
        }
    }

    public function test_un_lote_comprometido_no_se_puede_borrar(): void
    {
        foreach (['committed' => fn () => $this->f->committed(), 'active' => fn () => $this->f->active()] as $state => $make) {
            $id = $make();
            $this->assertDbRejects(fn () => DB::delete('DELETE FROM batches WHERE id = ?', [$id]), 'no se puede borrar en estado '.$state);
        }
    }

    // --- Transiciones ----------------------------------------------------------------------------------------------

    public function test_solo_se_permiten_las_transiciones_hacia_adelante(): void
    {
        $lifecycle = $this->f->lifecycle();
        $draft = $this->f->draftWithCodes();
        $committed = $this->f->committed();
        $active = $this->f->active();
        $closed = $this->f->active();
        $lifecycle->close($closed, 'tester');
        $revealed = $this->f->active();
        $lifecycle->close($revealed, 'tester');
        $lifecycle->reveal($revealed, 'tester');

        $illegal = [
            [$draft, 'active'], [$draft, 'closed'], [$draft, 'revealed'],
            [$committed, 'draft'], [$committed, 'closed'], [$committed, 'revealed'],
            [$active, 'draft'], [$active, 'committed'], [$active, 'revealed'],
            [$closed, 'draft'], [$closed, 'committed'], [$closed, 'active'],
            [$revealed, 'draft'], [$revealed, 'committed'], [$revealed, 'active'], [$revealed, 'closed'],
        ];
        foreach ($illegal as [$id, $to]) {
            $from = DB::table('batches')->where('id', $id)->value('state');
            $this->assertDbRejects(
                fn () => DB::update('UPDATE batches SET state = ? WHERE id = ?', [$to, $id]),
                "transición inválida: $from -> $to",
            );
        }
    }

    public function test_un_estado_inexistente_se_rechaza(): void
    {
        $id = $this->f->draft();
        $this->assertDbRejects(fn () => DB::update("UPDATE batches SET state = 'hackeado' WHERE id = ?", [$id]), 'transición inválida');
    }

    public function test_no_se_activa_un_lote_cuyos_codigos_no_tienen_premio(): void
    {
        $id = $this->f->committed();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE batches SET state = 'active', beacon_value = decode('aa', 'hex'), activated_at = now() WHERE id = ?", [$id]),
            'códigos sin premio asignado',
        );
    }

    // --- Campos de escritura única -------------------------------------------------------------------------------

    public function test_la_baliza_solo_se_fija_al_activar_y_no_se_cambia_despues(): void
    {
        $committed = $this->f->committed();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE batches SET beacon_value = decode('aa', 'hex') WHERE id = ?", [$committed]),
            'beacon_value solo se fija al activar',
        );

        $active = $this->f->active();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE batches SET beacon_value = decode('bb', 'hex') WHERE id = ?", [$active]),
            'beacon_value es de escritura única',
        );
    }

    public function test_la_semilla_solo_se_revela_al_pasar_a_revealed(): void
    {
        $active = $this->f->active();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE batches SET seed_revealed = decode(repeat('11', 32), 'hex') WHERE id = ?", [$active]),
            'la semilla solo se revela al pasar a revealed',
        );
    }

    public function test_la_semilla_revelada_no_se_cambia(): void
    {
        $id = $this->f->active();
        $this->f->lifecycle()->close($id, 'tester');
        $this->f->lifecycle()->reveal($id, 'tester');
        $this->assertDbRejects(
            fn () => DB::update("UPDATE batches SET seed_revealed = decode(repeat('22', 32), 'hex') WHERE id = ?", [$id]),
            'la semilla revelada es de escritura única',
        );
    }

    // --- Restricciones de completitud ----------------------------------------------------------------------------

    public function test_comprometer_exige_todos_los_campos_del_compromiso(): void
    {
        $id = $this->f->draftWithCodes();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE batches SET state = 'committed' WHERE id = ?", [$id]),
            'batches_committed_complete',
        );
    }

    public function test_un_borrador_no_puede_traer_datos_de_compromiso(): void
    {
        $id = $this->f->draft();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE batches SET commitment = decode(repeat('00', 32), 'hex') WHERE id = ?", [$id]),
            'batches_draft_clean',
        );
    }

    public function test_valores_fuera_de_rango_se_rechazan(): void
    {
        $id = $this->f->draft();
        $this->assertDbRejects(fn () => DB::update('UPDATE batches SET total_codes = 0 WHERE id = ?', [$id]), 'batches_total_codes_check');
        $this->assertDbRejects(fn () => DB::update('UPDATE batches SET total_codes = 1000001 WHERE id = ?', [$id]), 'batches_total_codes_check');
        $this->assertDbRejects(fn () => DB::update("UPDATE batches SET name = '   ' WHERE id = ?", [$id]), 'batches_name_check');
        $this->assertDbRejects(fn () => DB::update("UPDATE batches SET pool = '[]'::jsonb WHERE id = ?", [$id]), 'batches_pool_check');
        $this->assertDbRejects(fn () => DB::update("UPDATE batches SET pool = '{}'::jsonb WHERE id = ?", [$id]), 'batches_pool_check');
    }

    // --- Códigos -----------------------------------------------------------------------------------------------------

    public function test_la_identidad_de_un_codigo_es_inmutable(): void
    {
        $id = $this->f->committed();
        $other = $this->f->draft();
        $changes = [
            "code_hash = decode(repeat('00', 32), 'hex')",
            'idx = 999',
        ];
        foreach ($changes as $set) {
            $this->assertDbRejects(
                fn () => DB::update("UPDATE codes SET $set WHERE batch_id = ? AND idx = 0", [$id]),
                'la identidad de un código es inmutable',
            );
        }
        $this->assertDbRejects(
            fn () => DB::update('UPDATE codes SET batch_id = ? WHERE batch_id = ? AND idx = 0', [$other, $id]),
            'la identidad de un código es inmutable',
        );
    }

    public function test_el_premio_solo_se_asigna_al_activar_y_una_unica_vez(): void
    {
        $draft = $this->f->draftWithCodes();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE codes SET prize_enc = decode('aa', 'hex') WHERE batch_id = ? AND idx = 0", [$draft]),
            'el premio solo se asigna al activar el lote',
        );

        $committed = $this->f->committed();
        $this->assertSame(1, DB::update("UPDATE codes SET prize_enc = decode('aa', 'hex') WHERE batch_id = ? AND idx = 0", [$committed]));
        $this->assertDbRejects(
            fn () => DB::update("UPDATE codes SET prize_enc = decode('bb', 'hex') WHERE batch_id = ? AND idx = 0", [$committed]),
            'el premio asignado a un código es inmutable',
        );

        $active = $this->f->active();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE codes SET prize_enc = decode('cc', 'hex') WHERE batch_id = ? AND idx = 0", [$active]),
            'el premio asignado a un código es inmutable',
        );
    }

    public function test_un_premio_asignado_no_se_puede_borrar_ni_poner_a_null(): void
    {
        $active = $this->f->active();
        $this->assertDbRejects(
            fn () => DB::update('UPDATE codes SET prize_enc = NULL WHERE batch_id = ? AND idx = 0', [$active]),
            'el premio asignado a un código es inmutable',
        );
    }

    public function test_solo_se_redime_en_lotes_activos_y_una_sola_vez(): void
    {
        $committed = $this->f->committed();
        $this->assertDbRejects(
            fn () => DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'u1' WHERE batch_id = ? AND idx = 0", [$committed]),
            'solo se redimen códigos de lotes activos',
        );

        $active = $this->f->active();
        $this->assertSame(1, DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'u1' WHERE batch_id = ? AND idx = 0", [$active]));
        $this->assertDbRejects(
            fn () => DB::update("UPDATE codes SET redeemed_by = 'u2' WHERE batch_id = ? AND idx = 0", [$active]),
            'el código ya fue redimido',
        );
        $this->assertDbRejects(
            fn () => DB::update('UPDATE codes SET redeemed_at = NULL, redeemed_by = NULL WHERE batch_id = ? AND idx = 0', [$active]),
            'el código ya fue redimido',
        );

        $closed = $this->f->active();
        $this->f->lifecycle()->close($closed, 'tester');
        $this->assertDbRejects(
            fn () => DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'u1' WHERE batch_id = ? AND idx = 1", [$closed]),
            'solo se redimen códigos de lotes activos',
        );
    }

    public function test_redimir_exige_fecha_y_usuario_juntos(): void
    {
        $active = $this->f->active();
        $this->assertDbRejects(
            fn () => DB::update('UPDATE codes SET redeemed_at = now() WHERE batch_id = ? AND idx = 0', [$active]),
            'codes_redemption_pair',
        );
    }

    public function test_no_se_borran_codigos_de_un_lote_comprometido(): void
    {
        $id = $this->f->committed();
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM codes WHERE batch_id = ? AND idx = 0', [$id]), 'no se borran códigos de un lote comprometido');

        $draft = $this->f->draftWithCodes();
        $this->assertSame(1, DB::delete('DELETE FROM codes WHERE batch_id = ? AND idx = 0', [$draft]), 'en borrador sí se puede');
    }

    public function test_un_codigo_nuevo_no_puede_traer_premio_ni_redencion(): void
    {
        $id = $this->f->draft();
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO codes (batch_id, idx, code_hash, prize_enc) VALUES (?, 0, decode(repeat('ab', 32), 'hex'), decode('aa', 'hex'))", [$id]),
            'un código nuevo no puede traer premio ni redención',
        );
    }

    public function test_unicidad_y_rango_de_los_codigos(): void
    {
        $a = $this->f->draft();
        $b = $this->f->draft();
        DB::insert("INSERT INTO codes (batch_id, idx, code_hash) VALUES (?, 0, decode(repeat('ab', 32), 'hex'))", [$a]);

        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO codes (batch_id, idx, code_hash) VALUES (?, 0, decode(repeat('ab', 32), 'hex'))", [$b]),
            'codes_hash_unique',
        );
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO codes (batch_id, idx, code_hash) VALUES (?, 0, decode(repeat('cd', 32), 'hex'))", [$a]),
            'codes_batch_idx_unique',
        );
        $total = (int) DB::table('batches')->where('id', $a)->value('total_codes');
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO codes (batch_id, idx, code_hash) VALUES (?, ?, decode(repeat('ef', 32), 'hex'))", [$a, $total]),
            'fuera del rango del lote',
        );
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO codes (batch_id, idx, code_hash) VALUES (?, 1, decode('abcd', 'hex'))", [$a]),
            'codes_code_hash_check',
        );
    }

    // --- Premios ---------------------------------------------------------------------------------------------------

    public function test_la_definicion_de_un_premio_se_congela_al_comprometer_un_lote_que_lo_usa(): void
    {
        $campaign = $this->f->campaign();
        // Pool con none, p10k y p50k; p100k queda sin usar.
        $batch = $this->f->lifecycle()->createDraft($campaign, 'L', Fixtures::pool(), 'tester');
        $this->f->addCodes($batch, 10);

        // Mientras es borrador, el premio todavía se puede ajustar.
        $this->assertSame(1, DB::update("UPDATE prizes SET label = 'Diez mil' WHERE campaign_id = ? AND prize_id = 'p10k'", [$campaign]));

        $this->f->lifecycle()->commit($batch, 'tester');

        $this->assertDbRejects(
            fn () => DB::update("UPDATE prizes SET amount = 1 WHERE campaign_id = ? AND prize_id = 'p10k'", [$campaign]),
            'lo usa un lote comprometido',
        );
        $this->assertDbRejects(
            fn () => DB::delete("DELETE FROM prizes WHERE campaign_id = ? AND prize_id = 'none'", [$campaign]),
            'lo usa un lote comprometido',
        );
        // Un premio que ningún lote comprometido usa sigue siendo editable, y se pueden definir premios nuevos.
        $this->assertSame(1, DB::update("UPDATE prizes SET amount = 200000 WHERE campaign_id = ? AND prize_id = 'p100k'", [$campaign]));
        $this->assertSame(1, DB::insert("INSERT INTO prizes (campaign_id, prize_id, type, label, amount) VALUES (?, 'nuevo', 'points', '10 pts', 10)", [$campaign]) ? 1 : 0);
    }

    public function test_dinero_y_puntos_exigen_monto(): void
    {
        $campaign = $this->f->campaign();
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO prizes (campaign_id, prize_id, type, label) VALUES (?, 'x', 'money', 'sin monto')", [$campaign]),
            'prizes_check',
        );
    }

    // --- Bitácora ----------------------------------------------------------------------------------------------------

    public function test_la_bitacora_es_de_solo_agregar(): void
    {
        $this->f->draft(); // genera entradas de bitácora
        $this->assertGreaterThan(0, DB::table('audit_log')->count());

        $this->assertDbRejects(fn () => DB::update("UPDATE audit_log SET actor = 'otro'"), 'la bitácora es de solo agregar');
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM audit_log'), 'la bitácora es de solo agregar');
        $this->assertDbRejects(fn () => DB::statement('TRUNCATE audit_log'), 'la bitácora es de solo agregar');
    }

    public function test_la_cadena_de_la_bitacora_no_se_puede_bifurcar(): void
    {
        $this->f->draft();
        $first = DB::selectOne("SELECT encode(prev_hash, 'hex') AS p FROM audit_log ORDER BY id LIMIT 1");
        $this->assertDbRejects(
            fn () => DB::insert(
                "INSERT INTO audit_log (occurred_at, actor, action, subject_type, subject_id, prev_hash, hash)
                 VALUES (now(), 'x', 'x', 'x', 'x', decode(?, 'hex'), decode(repeat('ee', 32), 'hex'))",
                [$first->p],
            ),
            'audit_prev_unique',
        );
    }
}
