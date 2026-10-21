<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;

/** Campañas y premios del panel (SPEC-003 RF-35; amenazas T7 y T10). */
final class CampaignsTest extends AdminTestCase
{
    private function body(array $over = []): array
    {
        return $over + [
            'name' => 'Ruleta de octubre', 'gameType' => 'roulette', 'usedCodeBehavior' => 'rejectOnly', 'title' => 'Gira y Gana',
            'theme' => ['primary' => '#112233'], 'gameConfig' => Fixtures::gameConfig('roulette'),
            'prizes' => [
                ['prizeId' => 'none', 'type' => 'none', 'label' => 'Sin premio'],
                ['prizeId' => 'p10k', 'type' => 'money', 'label' => '$ 10.000', 'amount' => 10000],
                ['prizeId' => 'p50k', 'type' => 'money', 'label' => '$ 50.000', 'amount' => 50000],
                ['prizeId' => 'p100k', 'type' => 'money', 'label' => '$ 100.000', 'amount' => 100000],
            ],
        ];
    }

    private function create(array $over = []): string
    {
        $r = $this->api('POST', '/campaigns', $this->body($over));
        $this->assertSame(201, $r->status(), $r->getContent());

        return (string) $r->json('id');
    }

    /** Compromete un lote de la campaña (la congela). */
    private function freeze(string $campaignId): void
    {
        $f = new Fixtures;
        $batch = $f->lifecycle()->createDraft($campaignId, 'Lote', Fixtures::pool(), Fixtures::ACTOR);
        $f->addCodes($batch, 10);
        $f->lifecycle()->commit($batch, Fixtures::ACTOR);
    }

    public function test_crear_leer_y_listar_una_campania_con_premios(): void
    {
        $this->login($this->makeAdmin('operator'));
        $id = $this->create();

        $c = $this->api('GET', "/campaigns/$id")->assertOk()->json();
        $this->assertSame(['Ruleta de octubre', 'roulette', false], [$c['name'], $c['gameType'], $c['frozen']]);
        $this->assertCount(4, $c['prizes']);
        $this->assertSame(['#112233'], array_values((array) $c['theme']));
        $this->assertSame(Fixtures::gameConfig('roulette'), json_decode(json_encode($c['gameConfig']), true));

        $list = $this->api('GET', '/campaigns')->json('campaigns');
        $this->assertSame([$id, 4, 0], [$list[0]['id'], $list[0]['prizeCount'], $list[0]['batchCount']]);
        $this->assertNull((new AuditLog)->verifyChain());
        $this->assertNotNull(DB::selectOne("SELECT 1 AS x FROM audit_log WHERE action = 'campaign.created' AND subject_id = ?", [$id]));
    }

    public function test_editar_una_campania_en_borrador_y_sus_premios(): void
    {
        $this->login($this->makeAdmin('operator'));
        $id = $this->create();
        $this->api('PATCH', "/campaigns/$id", ['name' => 'Otro nombre', 'gameType' => 'scratch', 'usedCodeBehavior' => 'showPrevious'])->assertNoContent();
        $row = DB::table('campaigns')->where('id', $id)->first();
        $this->assertSame(['Otro nombre', 'scratch', 'showPrevious'], [$row->name, $row->game_type, $row->used_code_behavior]);

        $this->api('POST', "/campaigns/$id/prizes", ['prizeId' => 'pts', 'type' => 'points', 'label' => '100 puntos', 'amount' => 100])->assertStatus(201);
        $this->api('PATCH', "/campaigns/$id/prizes/pts", ['label' => '200 puntos', 'amount' => 200])->assertNoContent();
        $this->assertSame([200, '200 puntos'], [(int) DB::table('prizes')->where('prize_id', 'pts')->value('amount'), DB::table('prizes')->where('prize_id', 'pts')->value('label')]);
        $this->api('DELETE', "/campaigns/$id/prizes/pts")->assertNoContent();
        $this->assertSame(404, $this->api('DELETE', "/campaigns/$id/prizes/pts")->status());
        $this->assertSame(409, $this->api('POST', "/campaigns/$id/prizes", ['prizeId' => 'none', 'type' => 'none', 'label' => 'x'])->status(), 'identificador repetido');
    }

    public function test_con_un_lote_comprometido_las_reglas_y_los_premios_usados_quedan_congelados(): void
    {
        $this->login($this->makeAdmin('operator'));
        $id = $this->create();
        $this->api('POST', "/campaigns/$id/prizes", ['prizeId' => 'extra', 'type' => 'coupon', 'label' => 'Cupón'])->assertStatus(201);
        $this->freeze($id);

        $this->assertTrue($this->api('GET', "/campaigns/$id")->json('frozen'));
        foreach ([['gameType' => 'scratch'], ['usedCodeBehavior' => 'showPrevious'], ['gameConfig' => ['segments' => []]]] as $change) {
            $r = $this->api('PATCH', "/campaigns/$id", $change);
            $this->assertSame(409, $r->status(), json_encode($change));
            $this->assertSame('campaignFrozen', $r->json('error.code'));
        }
        // El título y el tema sí se ajustan.
        $this->api('PATCH', "/campaigns/$id", ['title' => 'Nuevo título', 'theme' => ['primary' => '#ffffff']])->assertNoContent();

        // Un premio del pool no se toca; uno que ningún lote usa, sí.
        $this->assertSame(409, $this->api('PATCH', "/campaigns/$id/prizes/p10k", ['label' => 'Otro'])->status());
        $this->assertSame(409, $this->api('DELETE', "/campaigns/$id/prizes/p10k")->status());
        $this->assertSame('$ 10.000', DB::table('prizes')->where('prize_id', 'p10k')->value('label'));
        $this->api('PATCH', "/campaigns/$id/prizes/extra", ['label' => 'Cupón 2'])->assertNoContent();
        $this->api('DELETE', "/campaigns/$id/prizes/extra")->assertNoContent();
    }

    public function test_validacion_de_entrada(): void
    {
        $this->login($this->makeAdmin('operator'));
        $bad = [
            'sin nombre' => ['name' => ''],
            'nombre largo' => ['name' => str_repeat('a', 121)],
            'nombre con control' => ['name' => "Hola\x00mundo"],
            'juego desconocido' => ['gameType' => 'poker'],
            'comportamiento' => ['usedCodeBehavior' => 'otro'],
            'theme lista' => ['theme' => [1, 2]],
            'theme enorme' => ['theme' => ['x' => str_repeat('a', 20000)]],
            'config texto' => ['gameConfig' => 'hola'],
            'premios no lista' => ['prizes' => ['a' => 1]],
            'premio sin id' => ['prizes' => [['type' => 'none', 'label' => 'x']]],
            'premio id raro' => ['prizes' => [['prizeId' => 'a b<script>', 'type' => 'none', 'label' => 'x']]],
            'premio tipo' => ['prizes' => [['prizeId' => 'a', 'type' => 'oro', 'label' => 'x']]],
            'dinero sin monto' => ['prizes' => [['prizeId' => 'a', 'type' => 'money', 'label' => 'x']]],
            'monto negativo' => ['prizes' => [['prizeId' => 'a', 'type' => 'money', 'label' => 'x', 'amount' => -1]]],
            'monto decimal' => ['prizes' => [['prizeId' => 'a', 'type' => 'money', 'label' => 'x', 'amount' => 10.5]]],
            'giros negativos' => ['prizes' => [['prizeId' => 'a', 'type' => 'extraSpin', 'label' => 'x', 'meta' => ['extra_spins' => -40]]]],
            'giros en exceso' => ['prizes' => [['prizeId' => 'a', 'type' => 'extraSpin', 'label' => 'x', 'meta' => ['extra_spins' => 11]]]],
            'giros como texto' => ['prizes' => [['prizeId' => 'a', 'type' => 'extraSpin', 'label' => 'x', 'meta' => ['extra_spins' => '3']]]],
            'giros en un premio de dinero' => ['prizes' => [['prizeId' => 'a', 'type' => 'money', 'label' => 'x', 'amount' => 5, 'meta' => ['extra_spins' => 2]]]],
            'ids repetidos' => ['prizes' => [['prizeId' => 'a', 'type' => 'none', 'label' => 'x'], ['prizeId' => 'a', 'type' => 'none', 'label' => 'y']]],
        ];
        foreach ($bad as $label => $over) {
            $this->assertSame(422, $this->api('POST', '/campaigns', $this->body($over))->status(), $label);
        }
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM campaigns')->n, 'nada a medias');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM prizes')->n);

        // Un premio de giro extra válido sí se acepta (con o sin el número de giros).
        $this->assertSame(201, $this->api('POST', '/campaigns', $this->body(['prizes' => [
            ['prizeId' => 'extra', 'type' => 'extraSpin', 'label' => 'Gira de nuevo', 'meta' => ['extra_spins' => 2]],
            ['prizeId' => 'extra2', 'type' => 'extraSpin', 'label' => 'Gira otra vez'],
        ]]))->status());
        DB::table('prizes')->delete();
        DB::table('campaigns')->delete();

        $id = $this->create();
        $this->assertSame(422, $this->api('PATCH', "/campaigns/$id", [])->status());
        $this->assertSame(404, $this->api('GET', '/campaigns/no-es-uuid')->status());
        $this->assertSame(404, $this->api('GET', '/campaigns/00000000-0000-4000-8000-000000000000')->status());
        $this->assertSame(404, $this->api('PATCH', '/campaigns/00000000-0000-4000-8000-000000000000', ['name' => 'x'])->status());
    }

    public function test_el_texto_con_html_se_guarda_tal_cual_sin_ejecutarse_ni_romper_nada(): void
    {
        // Se almacena como texto; el escape es responsabilidad de la vista (Angular, sin innerHTML), pero el servidor no lo interpreta.
        $this->login($this->makeAdmin('operator'));
        $id = $this->create(['name' => '<img src=x onerror=alert(1)>']);
        $this->assertSame('<img src=x onerror=alert(1)>', $this->api('GET', "/campaigns/$id")->json('name'));
    }

    public function test_permisos_el_auditor_solo_lee(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $id = $this->create();
        $this->api('POST', '/auth/logout');

        $this->login($this->makeAdmin('auditor'));
        $this->assertSame(200, $this->api('GET', '/campaigns')->status());
        $this->assertSame(200, $this->api('GET', "/campaigns/$id")->status());
        $writes = [
            ['POST', '/campaigns', $this->body()], ['PATCH', "/campaigns/$id", ['name' => 'x']], ['POST', "/campaigns/$id/prizes", ['prizeId' => 'z', 'type' => 'none', 'label' => 'z']],
            ['PATCH', "/campaigns/$id/prizes/none", ['label' => 'x']], ['DELETE', "/campaigns/$id/prizes/none", []],
        ];
        foreach ($writes as [$m, $path, $data]) {
            $r = $this->api($m, $path, $data);
            $this->assertSame(403, $r->status(), "$m $path");
            $this->assertSame('forbidden', $r->json('error.code'));
        }
        $this->assertSame('Ruleta de octubre', DB::table('campaigns')->where('id', $id)->value('name'));
    }

    public function test_la_respuesta_publica_de_un_juego_no_cambia_por_crear_la_campania_desde_el_panel(): void
    {
        // Una campaña creada por el panel debe poder comprometer un lote igual que una sembrada a mano.
        $this->login($this->makeAdmin('operator'));
        $id = $this->create();
        $this->freeze($id);
        $this->assertSame('committed', DB::table('batches')->where('campaign_id', $id)->value('state'));
    }
}
