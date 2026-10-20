<?php

namespace Tests\Feature\Batches;

use App\Domain\Batches\BeaconNotReady;
use App\Domain\Batches\InvalidBatch;
use App\Domain\Batches\InvalidTransition;
use App\Domain\Crypto\CodeHasher;
use App\Engine\Commitment;
use App\Engine\Pool;
use App\Engine\PrizeAssigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

final class BatchLifecycleTest extends DbTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
    }

    /** Premio (id) que tiene cada código del lote, descifrando el mapa. */
    private function prizesByIndex(string $batchId): array
    {
        $out = [];
        foreach (DB::select("SELECT idx, encode(prize_enc, 'hex') AS p FROM codes WHERE batch_id = ? ORDER BY idx", [$batchId]) as $r) {
            $out[(int) $r->idx] = $this->f->vault->decryptPrize($batchId, (int) $r->idx, hex2bin($r->p));
        }

        return $out;
    }

    // --- Borrador ------------------------------------------------------------------------------------------------

    public function test_crear_un_borrador_guarda_el_pool_canonico_y_deja_bitacora(): void
    {
        $campaign = $this->f->campaign();
        // Entradas en desorden: el pool se guarda en orden canónico.
        $id = $this->f->lifecycle()->createDraft($campaign, 'Lote A', [
            ['prize_id' => 'p50k', 'quantity' => 1], ['prize_id' => 'none', 'quantity' => 6], ['prize_id' => 'p10k', 'quantity' => 3],
        ], 'ana');

        $row = $this->f->batchRow($id);
        $this->assertSame('draft', $row->state);
        $this->assertSame(10, (int) $row->total_codes);
        $this->assertSame(
            [['prize_id' => 'none', 'quantity' => 6], ['prize_id' => 'p10k', 'quantity' => 3], ['prize_id' => 'p50k', 'quantity' => 1]],
            json_decode($row->pool, true),
        );
        $this->assertSame('batch.created', DB::table('audit_log')->value('action'));
        $this->assertSame('ana', DB::table('audit_log')->value('actor'));
    }

    public function test_un_pool_invalido_o_con_premios_inexistentes_se_rechaza(): void
    {
        $campaign = $this->f->campaign();
        $lc = $this->f->lifecycle();
        $malos = [
            'vacío' => [],
            'todo en cero' => [['prize_id' => 'none', 'quantity' => 0]],
            'cantidad negativa' => [['prize_id' => 'none', 'quantity' => -1], ['prize_id' => 'p10k', 'quantity' => 5]],
            'premio repetido' => [['prize_id' => 'none', 'quantity' => 1], ['prize_id' => 'none', 'quantity' => 2]],
            'premio que la campaña no define' => [['prize_id' => 'fantasma', 'quantity' => 1]],
        ];
        foreach ($malos as $nombre => $pool) {
            try {
                $lc->createDraft($campaign, 'L', $pool, 'ana');
                $this->fail("debía rechazar: $nombre");
            } catch (InvalidBatch) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('batches')->count(), 'ningún intento fallido debe dejar un lote a medias');
    }

    public function test_un_premio_de_otra_campania_no_vale(): void
    {
        $a = $this->f->campaign();
        $b = $this->f->campaign();
        DB::table('prizes')->insert(['campaign_id' => $b, 'prize_id' => 'solo-b', 'type' => 'points', 'label' => 'x', 'amount' => 1]);
        $this->expectException(InvalidBatch::class);
        $this->f->lifecycle()->createDraft($a, 'L', [['prize_id' => 'solo-b', 'quantity' => 1]], 'ana');
    }

    public function test_campania_inexistente(): void
    {
        $this->expectException(InvalidBatch::class);
        $this->f->lifecycle()->createDraft((string) Str::uuid(), 'L', Fixtures::pool(), 'ana');
    }

    public function test_los_codigos_se_numeran_seguido_entre_llamadas(): void
    {
        $id = $this->f->draft(); // 10 códigos
        $this->f->addCodes($id, 4);
        $this->f->addCodes($id, 6);
        $this->assertSame(range(0, 9), DB::table('codes')->where('batch_id', $id)->orderBy('idx')->pluck('idx')->map(fn ($i) => (int) $i)->all());
    }

    public function test_huellas_invalidas_o_repetidas_se_rechazan_sin_dejar_nada(): void
    {
        $id = $this->f->draft();
        $hasher = new CodeHasher;
        $lc = $this->f->lifecycle();

        try {
            $lc->addCodeHashes($id, ['corta'], 'ana');
            $this->fail('huella de tamaño incorrecto');
        } catch (InvalidBatch) {
            $this->addToAssertionCount(1);
        }

        $dup = $hasher->hash('X');
        try {
            $lc->addCodeHashes($id, [$hasher->hash('A'), $dup, $dup], 'ana');
            $this->fail('huella repetida');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, DB::table('codes')->where('batch_id', $id)->count(), 'la inserción falló entera: no queda un lote a medias');
    }

    public function test_no_se_agregan_codigos_fuera_del_borrador(): void
    {
        $id = $this->f->committed();
        $this->expectException(InvalidTransition::class);
        $this->f->addCodes($id, 1);
    }

    // --- Comprometer ---------------------------------------------------------------------------------------------

    public function test_no_se_compromete_un_lote_al_que_le_faltan_codigos(): void
    {
        $id = $this->f->draft();
        $this->f->addCodes($id, 9); // faltan 1
        try {
            $this->f->lifecycle()->commit($id, 'ana');
            $this->fail('debía rechazar');
        } catch (InvalidBatch $e) {
            $this->assertStringContainsString('9 códigos', $e->getMessage());
        }
        $this->assertSame('draft', $this->f->batchRow($id)->state);
    }

    public function test_comprometer_publica_el_compromiso_y_es_verificable(): void
    {
        $id = $this->f->draftWithCodes();
        $receipt = $this->f->lifecycle()->commit($id, 'ana');
        $row = $this->f->batchRow($id);

        $this->assertSame('committed', $row->state);
        $this->assertSame($receipt->commitmentHex, $row->commitment);
        $this->assertSame($this->f->beacon->nextRound, $receipt->beaconRound);
        $this->assertSame(bin2hex($this->f->beacon->chain), $receipt->chainHashHex);
        $this->assertSame(64, strlen($receipt->commitmentHex));

        // Cualquiera con la semilla podría recalcularlo: lo hacemos aquí con el motor, con la semilla descifrada.
        $seed = $this->f->vault->decryptSeed($id, hex2bin($row->seed_enc));
        $pool = Pool::fromEntries(json_decode($row->pool, true));
        $codeHashes = array_map(fn ($r) => hex2bin($r->h), DB::select("SELECT encode(code_hash,'hex') AS h FROM codes WHERE batch_id = ? ORDER BY idx", [$id]));
        $recomputed = Commitment::commit($id, $pool, Commitment::codesDigest($codeHashes), $this->f->beacon->chain, $receipt->beaconRound, $seed);
        $this->assertSame($receipt->commitmentHex, bin2hex($recomputed));
    }

    public function test_la_semilla_nunca_queda_en_claro_antes_de_revelarse(): void
    {
        $id = $this->f->committed();
        $row = $this->f->batchRow($id);
        $seed = $this->f->vault->decryptSeed($id, hex2bin($row->seed_enc));

        // 1) Lo guardado es texto cifrado, no la semilla.
        $this->assertStringNotContainsString(bin2hex($seed), $row->seed_enc);
        $this->assertNull($row->seed_revealed);

        // 2) No aparece en NINGUNA columna de ninguna fila de lotes, códigos ni bitácora (en claro, hex ni base64).
        $dump = json_encode([
            DB::select('SELECT * FROM audit_log'),
            DB::select("SELECT id, state, encode(commitment,'hex') c, encode(seed_enc,'hex') s, pool::text FROM batches"),
            DB::select("SELECT idx, encode(code_hash,'hex') h, encode(prize_enc,'hex') p FROM codes"),
        ]);
        $this->assertStringNotContainsString(bin2hex($seed), $dump);
        $this->assertStringNotContainsString(base64_encode($seed), $dump);
    }

    public function test_dos_compromisos_del_mismo_lote_no_se_pueden_encadenar(): void
    {
        $id = $this->f->committed();
        $this->expectException(InvalidTransition::class);
        $this->f->lifecycle()->commit($id, 'ana');
    }

    public function test_la_bitacora_de_comprometer_incluye_la_fotografia_de_los_premios(): void
    {
        $id = $this->f->committed();
        $entry = DB::selectOne("SELECT data::text AS d FROM audit_log WHERE action = 'batch.committed' AND subject_id = ?", [$id]);
        $data = json_decode($entry->d, true);
        $byId = array_column($data['prizes'], null, 'prize_id');
        $this->assertSame(10000, $byId['p10k']['amount']);
        $this->assertSame('money', $byId['p10k']['type']);
        $this->assertArrayNotHasKey('seed', $data);
    }

    // --- Activar -------------------------------------------------------------------------------------------------

    public function test_si_la_baliza_no_publico_la_ronda_el_lote_no_cambia(): void
    {
        $id = $this->f->committed(); // la ronda NO está publicada en la baliza simulada
        try {
            $this->f->lifecycle()->activate($id, 'ana');
            $this->fail('debía esperar a la baliza');
        } catch (BeaconNotReady $e) {
            $this->assertSame($this->f->beacon->nextRound, $e->round);
        }
        $this->assertSame('committed', $this->f->batchRow($id)->state);
        $this->assertSame(0, DB::table('codes')->where('batch_id', $id)->whereNotNull('prize_enc')->count(), 'ningún código recibió premio');

        // Y reintentar después, con la ronda ya publicada, funciona.
        $this->f->activate($id);
        $this->assertSame('active', $this->f->batchRow($id)->state);
    }

    public function test_si_la_baliza_esta_caida_nunca_se_activa_sin_ella(): void
    {
        $id = $this->f->committed();
        $this->f->beacon->publish($this->f->beacon->nextRound);
        $this->f->beacon->down = true;
        $this->expectException(\App\Domain\Beacon\BeaconUnavailable::class);
        try {
            $this->f->lifecycle()->activate($id, 'ana');
        } finally {
            $this->assertSame('committed', $this->f->batchRow($id)->state);
        }
    }

    public function test_ca20_al_activar_cada_codigo_tiene_premio_y_los_conteos_son_el_stock(): void
    {
        $pool = Fixtures::pool(none: 60, p10k: 30, p50k: 9, p100k: 1);
        $id = $this->f->active($pool);

        $prizes = $this->prizesByIndex($id);
        $this->assertCount(100, $prizes);
        $counts = array_count_values($prizes);
        ksort($counts);
        $this->assertSame(['none' => 60, 'p100k' => 1, 'p10k' => 30, 'p50k' => 9], $counts);
    }

    public function test_el_premio_de_cada_codigo_es_exactamente_el_que_calcula_el_motor(): void
    {
        $id = $this->f->active(Fixtures::pool(none: 40, p10k: 8, p50k: 2));
        $row = $this->f->batchRow($id);

        $seed = $this->f->vault->decryptSeed($id, hex2bin($row->seed_enc));
        $beacon = hex2bin($row->beacon_value);
        $expected = PrizeAssigner::assign(Pool::fromEntries(json_decode($row->pool, true)), $seed, $beacon, $id);

        $this->assertSame($expected, array_values($this->prizesByIndex($id)));
        $this->assertSame($this->f->beacon->published[(int) $row->beacon_round], $beacon, 'se usó el valor de la ronda comprometida');
    }

    public function test_el_premio_cifrado_esta_ligado_a_su_fila(): void
    {
        $id = $this->f->active();
        $rows = DB::select("SELECT idx, encode(prize_enc,'hex') AS p FROM codes WHERE batch_id = ? ORDER BY idx LIMIT 2", [$id]);
        [$a, $b] = $rows;

        // El texto cifrado del código 0 no se puede hacer pasar por el del código 1 (ni al revés).
        $this->expectException(\RuntimeException::class);
        $this->f->vault->decryptPrize($id, (int) $b->idx, hex2bin($a->p));
    }

    public function test_el_mapa_de_premios_no_se_puede_leer_sin_la_clave(): void
    {
        $id = $this->f->active();
        $any = DB::selectOne("SELECT encode(prize_enc,'hex') AS p FROM codes WHERE batch_id = ? AND idx = 0", [$id]);
        $this->assertStringNotContainsString(bin2hex('none'), $any->p);
        $this->assertStringNotContainsString(bin2hex('p10k'), $any->p);

        $otraClave = new \App\Domain\Crypto\PrizeVault(str_repeat('X', 32));
        $this->expectException(\RuntimeException::class);
        $otraClave->decryptPrize($id, 0, hex2bin($any->p));
    }

    public function test_no_se_activa_dos_veces_ni_un_borrador(): void
    {
        $id = $this->f->active();
        try {
            $this->f->lifecycle()->activate($id, 'ana');
            $this->fail('segunda activación');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidTransition::class);
        $this->f->lifecycle()->activate($this->f->draftWithCodes(), 'ana');
    }

    // --- Cerrar y revelar -----------------------------------------------------------------------------------------

    public function test_cerrar_solo_desde_activo_y_registra_cuantos_se_redimieron(): void
    {
        $id = $this->f->active();
        DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'u' WHERE batch_id = ? AND idx IN (0, 1, 2)", [$id]);
        $this->f->lifecycle()->close($id, 'ana');

        $this->assertSame('closed', $this->f->batchRow($id)->state);
        $entry = json_decode(DB::selectOne("SELECT data::text AS d FROM audit_log WHERE action = 'batch.closed' AND subject_id = ?", [$id])->d, true);
        $this->assertSame(3, $entry['redeemed']);

        $this->expectException(InvalidTransition::class);
        $this->f->lifecycle()->close($id, 'ana');
    }

    public function test_no_se_revela_un_lote_que_no_esta_cerrado(): void
    {
        $id = $this->f->active();
        $this->expectException(InvalidTransition::class);
        $this->f->lifecycle()->reveal($id, 'ana');
    }

    public function test_revelar_publica_la_semilla_que_reproduce_el_compromiso(): void
    {
        $id = $this->f->active(Fixtures::pool(none: 20, p10k: 5, p50k: 1));
        $this->f->lifecycle()->close($id, 'ana');
        $this->f->lifecycle()->reveal($id, 'ana');
        $row = $this->f->batchRow($id);

        $this->assertSame('revealed', $row->state);
        $seed = hex2bin($row->seed_revealed);
        $this->assertSame(32, strlen($seed));
        $this->assertSame($seed, $this->f->vault->decryptSeed($id, hex2bin($row->seed_enc)));

        $digest = hex2bin($row->codes_digest);
        $recomputed = Commitment::commit($id, Pool::fromEntries(json_decode($row->pool, true)), $digest, hex2bin($row->chain_hash), (int) $row->beacon_round, $seed);
        $this->assertSame($row->commitment, bin2hex($recomputed), 'un tercero puede reproducir el compromiso con la semilla revelada');

        $entry = json_decode(DB::selectOne("SELECT data::text AS d FROM audit_log WHERE action = 'batch.revealed' AND subject_id = ?", [$id])->d, true);
        $this->assertSame(bin2hex($seed), $entry['seed']);
    }

    // --- Integridad antes de revelar ------------------------------------------------------------------------------

    public function test_no_se_revela_si_alguien_altero_una_huella_de_codigo(): void
    {
        $id = $this->f->active();
        $this->f->lifecycle()->close($id, 'ana');

        // Simula a alguien con acceso SQL que desactiva el guardián y cambia una huella.
        DB::statement('ALTER TABLE codes DISABLE TRIGGER codes_guard_trg');
        DB::update("UPDATE codes SET code_hash = decode(repeat('00', 32), 'hex') WHERE batch_id = ? AND idx = 3", [$id]);

        try {
            $this->f->lifecycle()->reveal($id, 'ana');
            $this->fail('no debía revelar un lote alterado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('huellas de código', $e->getMessage());
        }
        $this->assertSame('closed', $this->f->batchRow($id)->state, 'no se reveló nada');
        $this->assertNull($this->f->batchRow($id)->seed_revealed);
    }

    public function test_no_se_revela_si_alguien_cambio_un_premio_asignado(): void
    {
        $id = $this->f->active(Fixtures::pool(none: 30, p10k: 10, p50k: 5));
        $this->f->lifecycle()->close($id, 'ana');

        // Intercambia, con el guardián apagado, los premios cifrados de dos códigos que tenían premios distintos.
        $prizes = $this->prizesByIndex($id);
        $iNone = array_search('none', $prizes, true);
        $iBig = array_search('p50k', $prizes, true);
        $vault = $this->f->vault;
        DB::statement('ALTER TABLE codes DISABLE TRIGGER codes_guard_trg');
        DB::update("UPDATE codes SET prize_enc = decode(?, 'hex') WHERE batch_id = ? AND idx = ?", [bin2hex($vault->encryptPrize($id, $iNone, 'p50k')), $id, $iNone]);

        try {
            $this->f->lifecycle()->reveal($id, 'ana');
            $this->fail('no debía revelar con un premio cambiado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no coincide con la baraja', $e->getMessage());
        }
        $this->assertNotSame($iNone, $iBig);
        $this->assertSame('closed', $this->f->batchRow($id)->state);
    }

    // --- Bitácora de todo el recorrido ------------------------------------------------------------------------------

    public function test_el_recorrido_completo_deja_la_bitacora_en_orden_y_con_cadena_integra(): void
    {
        $id = $this->f->active();
        $this->f->lifecycle()->close($id, 'ana');
        $this->f->lifecycle()->reveal($id, 'ana');

        $actions = DB::table('audit_log')->where('subject_id', $id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(
            ['batch.created', 'batch.codes_added', 'batch.committed', 'batch.activated', 'batch.closed', 'batch.revealed'],
            $actions,
        );
        $this->assertNull($this->f->audit->verifyChain(), 'la cadena de la bitácora debe ser íntegra');
    }

    public function test_una_transicion_que_falla_no_deja_entrada_de_bitacora(): void
    {
        $id = $this->f->draftWithCodes();
        $before = DB::table('audit_log')->count();
        try {
            $this->f->lifecycle()->close($id, 'ana'); // un borrador no se cierra
        } catch (InvalidTransition) {
        }
        $this->assertSame($before, DB::table('audit_log')->count(), 'no hay bitácora de cosas que no pasaron');
    }

    public function test_lote_inexistente(): void
    {
        $this->expectException(InvalidBatch::class);
        $this->f->lifecycle()->close((string) Str::uuid(), 'ana');
    }
}
