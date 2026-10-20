<?php

namespace Tests\Feature\Redemption;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DbTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RedeemFixtures;

/**
 * Las redenciones y la outbox se protegen EN LA BASE DE DATOS, igual que los lotes (ver SchemaGuardsTest): se atacan con SQL directo,
 * saltándose RedeemService, y cada intento debe ser rechazado por la razón correcta.
 */
final class RedemptionSchemaGuardsTest extends DbTestCase
{
    private RedeemFixtures $rf;

    /** @var array{batch: string, campaign: string, codes: list<string>, prizeOf: array<string, string>} */
    private array $b;

    private string $redemptionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rf = new RedeemFixtures;
        $this->b = $this->rf->activeBatch(Fixtures::pool(none: 4, p10k: 4, p50k: 2));
        $code = array_search('p10k', $this->b['prizeOf'], true);
        $this->redemptionId = $this->rf->service()->redeem('host-a:ana', 'clave-0000001', $code)->redemptionId;
    }

    private function redemptionRow(): object
    {
        return DB::selectOne('SELECT * FROM redemptions WHERE id = ?', [$this->redemptionId]);
    }

    /** Reclama directamente (como lo haría el servicio) un código libre y devuelve su id. */
    private function claimFreeCode(string $user): int
    {
        $id = (int) DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL ORDER BY idx LIMIT 1', [$this->b['batch']])->id;
        DB::update('UPDATE codes SET redeemed_at = now(), redeemed_by = ? WHERE id = ?', [$user, $id]);

        return $id;
    }

    private function insertRedemption(int $codeId, string $user, string $key, ?string $batch = null, string $response = '{}'): bool
    {
        return DB::insert(
            'INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response) VALUES (?, ?, ?, ?, ?, ?, ?::json)',
            [(string) Str::uuid(), $codeId, $batch ?? $this->b['batch'], $user, $key, 'none', $response],
        );
    }

    // --- Redenciones --------------------------------------------------------------------------------------------------

    public function test_una_redencion_es_inmutable(): void
    {
        $this->assertDbRejects(fn () => DB::update("UPDATE redemptions SET prize_id = 'p100k' WHERE id = ?", [$this->redemptionId]), 'inmutables');
        $this->assertDbRejects(fn () => DB::update("UPDATE redemptions SET user_id = 'otro' WHERE id = ?", [$this->redemptionId]), 'inmutables');
        $this->assertDbRejects(fn () => DB::update("UPDATE redemptions SET response = '{}'::json WHERE id = ?", [$this->redemptionId]), 'inmutables');
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM redemptions WHERE id = ?', [$this->redemptionId]), 'inmutables');
    }

    public function test_no_se_puede_registrar_una_redencion_de_un_codigo_que_nadie_reclamo(): void
    {
        $free = (int) DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL LIMIT 1', [$this->b['batch']])->id;
        $this->assertDbRejects(fn () => $this->insertRedemption($free, 'host-a:beto', 'clave-0000002'), 'no corresponde al código reclamado');
    }

    public function test_la_redencion_debe_ser_del_usuario_que_reclamo_el_codigo(): void
    {
        $code = $this->claimFreeCode('host-a:beto');
        $this->assertDbRejects(fn () => $this->insertRedemption($code, 'host-a:intruso', 'clave-0000002'), 'no corresponde al código reclamado');
    }

    public function test_la_redencion_debe_ser_del_lote_del_codigo(): void
    {
        $code = $this->claimFreeCode('host-a:beto');
        $otherBatch = $this->rf->f->draft();
        $this->assertDbRejects(fn () => $this->insertRedemption($code, 'host-a:beto', 'clave-0000002', $otherBatch), 'no corresponde al código reclamado');
    }

    public function test_un_codigo_solo_puede_tener_una_redencion(): void
    {
        $codeId = (int) DB::selectOne('SELECT code_id FROM redemptions WHERE id = ?', [$this->redemptionId])->code_id;
        $this->assertDbRejects(fn () => $this->insertRedemption($codeId, 'host-a:ana', 'clave-0000099'), 'redemptions_code_unique');
    }

    public function test_el_mismo_usuario_no_puede_repetir_la_clave_de_idempotencia(): void
    {
        $code = $this->claimFreeCode('host-a:ana');
        $this->assertDbRejects(fn () => $this->insertRedemption($code, 'host-a:ana', 'clave-0000001'), 'redemptions_user_key_unique');
    }

    public function test_otro_usuario_si_puede_usar_la_misma_clave(): void
    {
        $code = $this->claimFreeCode('host-a:beto');
        $this->assertTrue($this->insertRedemption($code, 'host-a:beto', 'clave-0000001'));
    }

    public function test_restricciones_de_formato(): void
    {
        $code = $this->claimFreeCode('host-a:beto');
        $this->assertDbRejects(fn () => $this->insertRedemption($code, 'host-a:beto', 'corta'), 'redemptions_idempotency_key_check');
        // 129 caracteres: lo rechaza el propio tipo varchar(128), antes de llegar al CHECK.
        $this->assertDbRejects(fn () => $this->insertRedemption($code, 'host-a:beto', str_repeat('k', 129)), 'value too long');
        $this->assertDbRejects(fn () => $this->insertRedemption($code, 'host-a:beto', 'clave-0000002', null, '[1,2]'), 'redemptions_response_check');
        // Un usuario vacío: se reclama el código "a nombre de nadie" para que el trigger de integridad cruzada no salte antes que el CHECK.
        $blank = $this->claimFreeCode('');
        $this->assertDbRejects(fn () => $this->insertRedemption($blank, '', 'clave-0000003'), 'redemptions_user_id_check');
    }

    public function test_la_respuesta_se_guarda_byte_a_byte_y_no_normalizada(): void
    {
        // Es `json` y no `jsonb`: jsonb reordenaría las claves y el reintento dejaría de ser idéntico a la primera respuesta.
        $code = $this->claimFreeCode('host-a:beto');
        $raw = '{"z":1,"a":{"y":2,"x":[3,2,1]},"vacio":{}}';
        $this->insertRedemption($code, 'host-a:beto', 'clave-0000002', null, $raw);
        $this->assertSame($raw, DB::selectOne("SELECT response::text AS r FROM redemptions WHERE user_id = 'host-a:beto'")->r);
    }

    // --- Outbox ----------------------------------------------------------------------------------------------------------

    private function outbox(): object
    {
        return DB::selectOne('SELECT * FROM prize_outbox WHERE redemption_id = ?', [$this->redemptionId]);
    }

    public function test_un_premio_tiene_a_lo_sumo_un_aviso_de_acreditacion(): void
    {
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO prize_outbox (redemption_id, payload) VALUES (?, '{}'::jsonb)", [$this->redemptionId]),
            'prize_outbox_redemption_unique',
        );
    }

    public function test_el_aviso_no_existe_sin_su_redencion(): void
    {
        $this->assertDbRejects(
            fn () => DB::insert("INSERT INTO prize_outbox (redemption_id, payload) VALUES (?, '{}'::jsonb)", [(string) Str::uuid()]),
            'violates foreign key',
        );
    }

    public function test_el_contenido_del_aviso_es_inmutable_solo_cambia_su_estado_de_entrega(): void
    {
        $this->assertDbRejects(fn () => DB::update("UPDATE prize_outbox SET payload = '{}'::jsonb WHERE redemption_id = ?", [$this->redemptionId]), 'es inmutable');
        $this->assertDbRejects(fn () => DB::update('UPDATE prize_outbox SET redemption_id = ? WHERE redemption_id = ?', [(string) Str::uuid(), $this->redemptionId]), 'es inmutable');
        $this->assertDbRejects(fn () => DB::update("UPDATE prize_outbox SET created_at = now() - interval '1 day' WHERE redemption_id = ?", [$this->redemptionId]), 'es inmutable');
        $this->assertSame(1, DB::update("UPDATE prize_outbox SET attempts = 1, last_error = 'x', next_attempt_at = now() WHERE redemption_id = ?", [$this->redemptionId]));
    }

    public function test_un_aviso_entregado_ya_no_se_modifica(): void
    {
        DB::update("UPDATE prize_outbox SET status = 'sent', sent_at = now() WHERE redemption_id = ?", [$this->redemptionId]);
        $this->assertDbRejects(fn () => DB::update("UPDATE prize_outbox SET status = 'pending', sent_at = NULL WHERE redemption_id = ?", [$this->redemptionId]), 'ya entregado');
        $this->assertDbRejects(fn () => DB::update("UPDATE prize_outbox SET sent_at = now() + interval '1 hour' WHERE redemption_id = ?", [$this->redemptionId]), 'ya entregado');
        $this->assertDbRejects(fn () => DB::update("UPDATE prize_outbox SET status = 'failed', sent_at = NULL WHERE redemption_id = ?", [$this->redemptionId]), 'ya entregado');
    }

    public function test_estado_y_fecha_de_envio_son_consistentes(): void
    {
        $this->assertDbRejects(fn () => DB::update("UPDATE prize_outbox SET status = 'sent' WHERE redemption_id = ?", [$this->redemptionId]), 'prize_outbox_sent_consistent');
        $this->assertDbRejects(fn () => DB::update("UPDATE prize_outbox SET sent_at = now() WHERE redemption_id = ?", [$this->redemptionId]), 'prize_outbox_sent_consistent');
        $this->assertDbRejects(fn () => DB::update("UPDATE prize_outbox SET status = 'inventado' WHERE redemption_id = ?", [$this->redemptionId]), 'prize_outbox_status_check');
        $this->assertDbRejects(fn () => DB::update('UPDATE prize_outbox SET attempts = -1 WHERE redemption_id = ?', [$this->redemptionId]), 'prize_outbox_attempts_check');
    }

    public function test_un_aviso_fallido_se_puede_reintentar_a_mano(): void
    {
        DB::update("UPDATE prize_outbox SET status = 'failed', attempts = 8, last_error = 'x' WHERE redemption_id = ?", [$this->redemptionId]);
        $this->assertSame(1, DB::update("UPDATE prize_outbox SET status = 'pending', attempts = 0, next_attempt_at = now() WHERE redemption_id = ?", [$this->redemptionId]));
        $this->assertSame('pending', $this->outbox()->status);
    }

    public function test_la_outbox_no_se_borra(): void
    {
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM prize_outbox WHERE redemption_id = ?', [$this->redemptionId]), 'no se borra');
    }
}
