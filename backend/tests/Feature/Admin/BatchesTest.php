<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Codes\CodeFormat;
use App\Domain\Crypto\CodeHasher;
use App\Domain\Crypto\PrizeVault;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Fixtures;

/** Lotes del panel (SPEC-003 RF-36/37). */
final class BatchesTest extends AdminTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
        $this->app->instance(BeaconClient::class, $this->f->beacon);
    }

    private function pool(): array
    {
        return [['prizeId' => 'none', 'quantity' => 6], ['prizeId' => 'p10k', 'quantity' => 3], ['prizeId' => 'p50k', 'quantity' => 1]];
    }

    public function test_crear_un_borrador_leerlo_y_listarlo(): void
    {
        $this->login($this->makeAdmin('operator'));
        $campaign = $this->f->campaign();

        $r = $this->api('POST', '/batches', ['campaignId' => $campaign, 'name' => 'Lote A', 'pool' => $this->pool()]);
        $this->assertSame(201, $r->status(), $r->getContent());
        $id = $r->json('id');

        $b = $this->api('GET', "/batches/$id")->assertOk()->json();
        $this->assertSame(['draft', 10, 0, 0], [$b['state'], $b['totalCodes'], $b['generatedCodes'], $b['redeemed']]);
        $this->assertNull($b['seed']);
        $this->assertArrayNotHasKey('seed_enc', $b);
        $this->assertSame([$id], array_column($this->api('GET', "/batches?campaignId=$campaign&state=draft")->json('batches'), 'id'));
        $this->assertSame([], $this->api('GET', '/batches?state=active')->json('batches'));
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_un_lote_con_reserva_se_crea_y_el_detalle_la_muestra(): void
    {
        $this->login($this->makeAdmin('operator'));
        $campaign = $this->f->campaign();
        DB::table('prizes')->insert(['campaign_id' => $campaign, 'prize_id' => 'extra', 'type' => 'extraSpin', 'label' => 'Gira de nuevo', 'amount' => null, 'meta' => json_encode(['extra_spins' => 1])]);
        $pool = [['prizeId' => 'none', 'quantity' => 6], ['prizeId' => 'p10k', 'quantity' => 3], ['prizeId' => 'extra', 'quantity' => 2]];

        $r = $this->api('POST', '/batches', ['campaignId' => $campaign, 'name' => 'Con reserva', 'pool' => $pool, 'reserve' => 3]);
        $this->assertSame(201, $r->status(), $r->getContent());
        $b = $this->api('GET', '/batches/'.$r->json('id'))->assertOk()->json();
        $this->assertSame([8, 3, 0, 0, 2], [$b['totalCodes'], $b['reserveSize'], $b['reserveConsumed'], $b['pendingSpins'], $b['commitVersion']]);
        $this->assertSame(3, $this->api('GET', '/batches')->json('batches.0.reserveSize'));

        // Sin reserva el detalle no trae campos de reserva.
        $plain = $this->api('POST', '/batches', ['campaignId' => $campaign, 'name' => 'Sin', 'pool' => $this->pool()])->json('id');
        $this->assertArrayNotHasKey('reserveConsumed', $this->api('GET', "/batches/$plain")->json());
    }

    public function test_la_reserva_se_valida(): void
    {
        $this->login($this->makeAdmin('operator'));
        $campaign = $this->f->campaign();
        DB::table('prizes')->insert(['campaign_id' => $campaign, 'prize_id' => 'extra', 'type' => 'extraSpin', 'label' => 'Gira de nuevo', 'amount' => null, 'meta' => json_encode(['extra_spins' => 1])]);
        $ok = ['campaignId' => $campaign, 'name' => 'L', 'pool' => [['prizeId' => 'none', 'quantity' => 6], ['prizeId' => 'extra', 'quantity' => 2]]];

        foreach ([['reserve' => -1], ['reserve' => '3'], ['reserve' => 1.5], ['reserve' => 8], ['reserve' => 1]] as $bad) {
            $r = $this->api('POST', '/batches', $bad + $ok);
            $this->assertSame(422, $r->status(), json_encode($bad).' '.$r->getContent());
        }
        // 2 premios de giro extra piden 2 casillas: con 1 no alcanza (error duro), con 2 sí.
        $this->assertStringContainsString('no cubre el peor caso', $this->api('POST', '/batches', ['reserve' => 1] + $ok)->json('error.message'));
        $this->assertSame(201, $this->api('POST', '/batches', ['reserve' => 2] + $ok)->status());
    }

    public function test_validacion_del_borrador(): void
    {
        $this->login($this->makeAdmin('operator'));
        $campaign = $this->f->campaign();
        $ok = ['campaignId' => $campaign, 'name' => 'L', 'pool' => $this->pool()];

        foreach ([
            ['name' => "con\x07control"], ['name' => '   '], ['name' => str_repeat('x', 121)], ['pool' => []], ['pool' => 'x'],
            ['pool' => [['prizeId' => 'none', 'quantity' => 0]]], ['pool' => [['prizeId' => 'none', 'quantity' => '5']]],
            ['pool' => [['prizeId' => 'none', 'quantity' => 1], ['prizeId' => 'none', 'quantity' => 1]]],
            ['pool' => [['prizeId' => 'inexistente', 'quantity' => 1]]], ['redeemableUntil' => 'mañana tal vez'],
        ] as $bad) {
            $r = $this->api('POST', '/batches', $bad + $ok);
            $this->assertSame(422, $r->status(), json_encode($bad).' '.$r->getContent());
            $this->assertSame('validationFailed', $r->json('error.code'));
        }
        $this->assertSame(404, $this->api('POST', '/batches', ['campaignId' => '00000000-0000-4000-8000-000000000000'] + $ok)->status());
        $this->assertSame(0, DB::table('batches')->count());
    }

    public function test_comprometer_cerrar_y_revelar_siguen_el_ciclo_y_rechazan_el_desorden(): void
    {
        $this->login($this->makeAdmin('operator'));
        $id = $this->f->draftWithCodes();

        $this->api('POST', "/batches/$id/close")->assertStatus(409);
        $r = $this->api('POST', "/batches/$id/commit");
        $this->assertSame(200, $r->status(), $r->getContent());
        $this->assertSame(64, strlen($r->json('commitment')));
        $this->api('POST', "/batches/$id/commit")->assertStatus(409);

        $b = $this->api('GET', "/batches/$id")->json();
        $this->assertSame('committed', $b['state']);
        $this->assertNull($b['seed']);

        $this->f->activate($id);
        $this->api('POST', "/batches/$id/reveal")->assertStatus(409);
        $this->api('POST', "/batches/$id/close")->assertNoContent();
        $this->api('POST', "/batches/$id/reveal")->assertNoContent();
        $b = $this->api('GET', "/batches/$id")->json();
        $this->assertSame('revealed', $b['state']);
        $this->assertSame(64, strlen($b['seed']));
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_comprometer_sin_todos_los_codigos_es_un_conflicto_claro(): void
    {
        $this->login($this->makeAdmin('operator'));
        $id = $this->f->draft();
        $this->f->addCodes($id, 3);
        $r = $this->api('POST', "/batches/$id/commit");
        $this->assertSame(409, $r->status());
        $this->assertSame('batchNotReady', $r->json('error.code'));
        $this->assertSame('draft', DB::table('batches')->where('id', $id)->value('state'));
    }

    public function test_activar_no_se_puede_hacer_directo(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $id = $this->f->committed();
        $this->api('POST', "/batches/$id/activate")->assertStatus(404);
        $this->assertSame('committed', DB::table('batches')->where('id', $id)->value('state'));
    }

    public function test_id_mal_formado_o_inexistente_es_404(): void
    {
        $this->login($this->makeAdmin('operator'));
        $this->api('GET', '/batches/no-es-uuid')->assertStatus(404);
        $this->api('GET', '/batches/00000000-0000-4000-8000-000000000000')->assertStatus(404);
        $this->api('POST', '/batches/00000000-0000-4000-8000-000000000000/commit')->assertStatus(404);
        $this->api('GET', '/batches/00000000-0000-4000-8000-000000000000/redemptions')->assertStatus(404);
    }

    public function test_el_auditor_lee_pero_no_modifica(): void
    {
        $id = $this->f->draftWithCodes();
        $this->login($this->makeAdmin('auditor'));
        $this->api('GET', '/batches')->assertOk();
        $this->api('GET', "/batches/$id")->assertOk();
        $this->api('GET', "/batches/$id/redemptions")->assertOk();
        foreach (['commit', 'close', 'reveal'] as $a) {
            $this->api('POST', "/batches/$id/$a")->assertStatus(403);
        }
        $this->api('POST', '/batches', ['campaignId' => $this->f->campaign(), 'name' => 'x', 'pool' => $this->pool()])->assertStatus(403);
        $this->assertSame('draft', DB::table('batches')->where('id', $id)->value('state'));
    }

    /** @return array{0: string, 1: string} lote activo y un código en claro de él */
    private function activeWithKnownCode(): array
    {
        $id = $this->f->draft();
        // El primer código (idx 0) es uno cuyo texto conocemos; los demás son de relleno.
        $canonical = CodeFormat::canonicalFromBytes(str_repeat("\x01", 7));
        $text = CodeFormat::display($canonical, 'SG');
        $hasher = new CodeHasher;
        $hashes = [$hasher->hash($canonical)];
        for ($i = 1; $i < 10; $i++) {
            $hashes[] = $hasher->hash('RELLENO-'.$id.'-'.$i);
        }
        $this->f->lifecycle()->addCodeHashes($id, $hashes, Fixtures::ACTOR);
        $this->f->lifecycle()->commit($id, Fixtures::ACTOR);
        $this->f->activate($id);

        return [$id, $text];
    }

    public function test_buscar_un_codigo_no_revela_el_premio_si_no_se_ha_canjeado(): void
    {
        $this->login($this->makeAdmin('auditor'));
        [$id, $text] = $this->activeWithKnownCode();

        $r = $this->api('POST', '/codes/lookup', ['code' => strtolower($text)]);
        $this->assertSame(200, $r->status(), $r->getContent());
        $this->assertFalse($r->json('redeemed'));
        $this->assertNull($r->json('prizeId'));
        $this->assertSame([$id, 'active', 0], [$r->json('batchId'), $r->json('batchState'), $r->json('index')]);
        $this->assertStringNotContainsString('prize_enc', $r->getContent());
        $this->assertNotNull(DB::selectOne("SELECT 1 AS x FROM audit_log WHERE action = 'code.lookup'"));
    }

    public function test_buscar_un_codigo_canjeado_muestra_quien_y_que_premio(): void
    {
        $this->login($this->makeAdmin('operator'));
        [$id, $text] = $this->activeWithKnownCode();
        $canon = CodeFormat::normalize($text);
        $codeId = DB::table('codes')->where('batch_id', $id)->where('idx', 0)->value('id');
        $prize = app(PrizeVault::class)->decryptPrize($id, 0, hex2bin(DB::selectOne("SELECT encode(prize_enc,'hex') AS p FROM codes WHERE id = ?", [$codeId])->p));
        DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'u-77' WHERE id = ?", [$codeId]);
        DB::insert(
            "INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response) VALUES (?, ?, ?, 'u-77', 'idem-key-0001', ?, '{}')",
            [Str::uuid(), $codeId, $id, $prize],
        );

        $r = $this->api('POST', '/codes/lookup', ['code' => $text])->assertOk();
        $this->assertSame([true, 'u-77', $prize], [$r->json('redeemed'), $r->json('redeemedBy'), $r->json('prizeId')]);

        $list = $this->api('GET', "/batches/$id/redemptions")->assertOk()->json();
        $this->assertSame(1, $list['total']);
        $this->assertSame(['u-77', $prize, 0], [$list['items'][0]['userId'], $list['items'][0]['prizeId'], $list['items'][0]['index']]);
        $this->assertSame(1, $this->api('GET', "/batches/$id")->json('redeemed'));
        $this->assertNotNull($canon);
    }

    public function test_buscar_un_texto_invalido_o_inexistente(): void
    {
        $this->login($this->makeAdmin('operator'));
        $this->assertSame('codeInvalid', $this->api('POST', '/codes/lookup', ['code' => 'esto no es un código'])->json('error.code'));
        $this->api('POST', '/codes/lookup', ['code' => 123])->assertStatus(422);
        $this->api('POST', '/codes/lookup', ['code' => str_repeat('A', 65)])->assertStatus(422);
        $canon = CodeFormat::canonicalFromBytes(str_repeat("\x02", 7));
        $this->api('POST', '/codes/lookup', ['code' => CodeFormat::display($canon, 'SG')])->assertStatus(404);
    }

    public function test_la_paginacion_de_canjes_tiene_tope(): void
    {
        $this->login($this->makeAdmin('operator'));
        $id = $this->f->draftWithCodes();
        $r = $this->api('GET', "/batches/$id/redemptions?perPage=100000&page=-4")->assertOk();
        $this->assertSame([1, 200], [$r->json('page'), $r->json('perPage')]);
    }

    public function test_las_busquedas_de_codigo_tienen_limite_por_usuario(): void
    {
        $admin = $this->makeAdmin('auditor');
        $this->login($admin);
        DB::table('settings')->insert(['key' => 'admin.lookup_per_minute', 'value' => '3', 'updated_by' => 'test', 'updated_at' => now()]);
        Cache::flush();
        $canon = CodeFormat::canonicalFromBytes(str_repeat("\x03", 7));
        $body = ['code' => CodeFormat::display($canon, 'SG')];

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(404, $this->api('POST', '/codes/lookup', $body)->status());
        }
        $r = $this->api('POST', '/codes/lookup', $body);
        $this->assertSame([429, 'rateLimited'], [$r->status(), $r->json('error.code')]);
        $this->assertNotEmpty($r->headers->get('Retry-After'));
        $this->assertSame(3, DB::table('audit_log')->where('action', 'code.lookup')->count(), 'lo rechazado no escribe en la bitácora');
    }
}
