<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Settings\Settings;
use App\Domain\Admin\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;

/** Control dual (SPEC-003 P3, RF-43/44/45, CA-32, amenazas T11–T15). */
final class ApprovalsTest extends AdminTestCase
{
    private const KEY = 'token.max_lifetime_seconds';

    private function asUser(array $admin): void
    {
        $this->api('POST', '/auth/logout');
        $this->login($admin);
    }

    /** @return array<string, mixed> */
    private function settingParams(int $value = 900): array
    {
        return ['action' => 'setting.change', 'params' => ['key' => self::KEY, 'value' => $value]];
    }

    private function request(array $body): int
    {
        $r = $this->api('POST', '/approvals', $body);
        $this->assertSame(201, $r->status(), $r->getContent());

        return (int) $r->json('id');
    }

    private function approve(int $id, array $admin): \Illuminate\Testing\TestResponse
    {
        return $this->api('POST', "/approvals/$id/approve", ['code' => $this->nextCode($admin['secret'])]);
    }

    /** @return array{0: array, 1: array} dos superadmin */
    private function pair(): array
    {
        return [$this->makeAdmin('superadmin'), $this->makeAdmin('superadmin')];
    }

    public function test_flujo_completo_solicitar_aprobar_ejecutar_cambia_un_parametro_de_seguridad(): void
    {
        [$a, $b] = $this->pair();
        $this->login($a);
        $id = $this->request($this->settingParams(900));
        $this->assertNotSame(900, Settings::int(self::KEY));

        $this->asUser($b);
        $this->approve($id, $b)->assertNoContent();
        $this->assertNotSame(900, Settings::int(self::KEY), 'aprobar no ejecuta');

        $this->asUser($a);
        $this->api('POST', "/approvals/$id/execute")->assertNoContent();
        $this->assertSame(900, Settings::int(self::KEY));

        $row = DB::selectOne('SELECT * FROM approvals WHERE id = ?', [$id]);
        $this->assertSame([$a['id'], $b['id'], 'executed'], [(int) $row->requested_by, (int) $row->approved_by, $row->status]);
        $this->assertNotNull($row->executed_at);
        foreach (['approval.requested', 'approval.approved', 'approval.executed', 'settings.changed'] as $action) {
            $this->assertNotNull(DB::selectOne('SELECT 1 AS x FROM audit_log WHERE action = ?', [$action]), $action);
        }
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_nadie_se_autoaprueba_ni_en_el_servicio_ni_en_la_base(): void
    {
        [$a] = $this->pair();
        $this->login($a);
        $id = $this->request($this->settingParams());
        $r = $this->approve($id, $a);
        $this->assertSame(403, $r->status());
        $this->assertSame('selfApproval', $r->json('error.code'));

        $this->assertDbRejects(fn () => DB::update("UPDATE approvals SET status = 'approved', approved_by = requested_by, approved_at = now() WHERE id = ?", [$id]), 'approvals_no_self_approval');
    }

    public function test_un_codigo_incorrecto_o_ya_usado_no_aprueba_y_no_cambia_el_estado(): void
    {
        [$a, $b] = $this->pair();
        $this->login($a);
        $id = $this->request($this->settingParams());
        $this->asUser($b);

        $r = $this->api('POST', "/approvals/$id/approve", ['code' => '000000']);
        $this->assertSame(422, $r->status());
        $this->assertSame('invalidCode', $r->json('error.code'));

        // El código del paso con el que se inició sesión ya se usó: no sirve para aprobar (se exige uno NUEVO).
        $stale = Totp::code($b['secret'], Totp::step(Carbon::now()->getTimestamp()));
        $this->assertSame(422, $this->api('POST', "/approvals/$id/approve", ['code' => $stale])->status());
        $this->assertSame('pending', DB::table('approvals')->where('id', $id)->value('status'));
    }

    public function test_ejecutar_exige_estar_aprobada_ser_el_solicitante_y_solo_funciona_una_vez(): void
    {
        [$a, $b] = $this->pair();
        $this->login($a);
        $id = $this->request($this->settingParams());

        $this->assertSame('wrongState', $this->api('POST', "/approvals/$id/execute")->json('error.code'), 'sin aprobar');

        $this->asUser($b);
        $this->approve($id, $b)->assertNoContent();
        $r = $this->api('POST', "/approvals/$id/execute");
        $this->assertSame(403, $r->status());
        $this->assertSame('notRequester', $r->json('error.code'), 'el aprobador no ejecuta');

        $this->asUser($a);
        $this->api('POST', "/approvals/$id/execute")->assertNoContent();
        $again = $this->api('POST', "/approvals/$id/execute");
        $this->assertSame(409, $again->status());
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM settings_history WHERE key = ?", [self::KEY])->n, 'se aplicó una sola vez');
    }

    public function test_una_solicitud_caduca_al_aprobar_y_al_ejecutar(): void
    {
        [$a, $b] = $this->pair();
        // Caducidad de 1 h (parámetro) para no cruzar el límite absoluto de la sesión del panel (12 h) dentro de la prueba.
        DB::insert("INSERT INTO settings (key, value, updated_by) VALUES ('admin.approval_ttl_hours', '1'::jsonb, 'test')");
        $this->login($a);
        $id = $this->request($this->settingParams());
        $this->advance(3600 + 60);
        $this->asUser($b);
        $r = $this->approve($id, $b);
        $this->assertSame('expired', $r->json('error.code'));

        // Aprobada a tiempo pero ejecutada tarde: tampoco.
        $this->asUser($a);
        $id2 = $this->request($this->settingParams(800));
        $this->asUser($b);
        $this->approve($id2, $b)->assertNoContent();
        $this->advance(3600 + 60);
        $this->asUser($a);
        $this->assertSame('expired', $this->api('POST', "/approvals/$id2/execute")->json('error.code'));
        $this->assertNotSame(800, Settings::int(self::KEY));

        // La vencida no bloquea una solicitud nueva para el mismo parámetro.
        $this->assertGreaterThan($id2, $this->request($this->settingParams(700)));
    }

    public function test_los_params_aprobados_son_inmutables_y_la_base_protege_el_resto(): void
    {
        [$a, $b] = $this->pair();
        $this->login($a);
        $id = $this->request($this->settingParams(900));
        $this->assertDbRejects(fn () => DB::update("UPDATE approvals SET params = '{\"key\":\"token.max_lifetime_seconds\",\"value\":3600}'::jsonb WHERE id = ?", [$id]), 'inmutables');
        $this->assertDbRejects(fn () => DB::update('UPDATE approvals SET requested_by = ? WHERE id = ?', [$b['id'], $id]), 'inmutables');
        $this->assertDbRejects(fn () => DB::update("UPDATE approvals SET status = 'executed', executed_at = now() WHERE id = ?", [$id]), 'transición');
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM approvals WHERE id = ?', [$id]), 'no se borran');
        $this->assertDbRejects(fn () => DB::unprepared('TRUNCATE approvals'), 'no se borran');

        $this->asUser($b);
        $this->approve($id, $b)->assertNoContent();
        $this->assertDbRejects(fn () => DB::update('UPDATE approvals SET approved_by = ? WHERE id = ?', [$a['id'], $id]), 'write-once');
        DB::update("UPDATE approvals SET status = 'executed', executed_at = now() WHERE id = ?", [$id]);
        $this->assertDbRejects(fn () => DB::update("UPDATE approvals SET status = 'pending' WHERE id = ?", [$id]), 'transición');
        $this->assertDbRejects(fn () => DB::update('UPDATE approvals SET executed_at = now() + interval \'1 day\' WHERE id = ?', [$id]), 'write-once');
    }

    public function test_solo_una_solicitud_abierta_por_objeto(): void
    {
        [$a] = $this->pair();
        $this->login($a);
        $this->request($this->settingParams(900));
        $r = $this->api('POST', '/approvals', $this->settingParams(800));
        $this->assertSame(409, $r->status());
        $this->assertSame('alreadyPending', $r->json('error.code'));
    }

    public function test_parametros_invalidos_se_rechazan_al_solicitar(): void
    {
        [$a] = $this->pair();
        $this->login($a);
        $bad = [
            ['action' => 'nada', 'params' => []],
            ['action' => 'setting.change', 'params' => ['key' => 'no.existe', 'value' => 1]],
            ['action' => 'setting.change', 'params' => ['key' => 'outbox.max_attempts', 'value' => 5]], // no es de seguridad
            ['action' => 'setting.change', 'params' => ['key' => self::KEY, 'value' => 999999]],       // fuera de rango
            ['action' => 'setting.change', 'params' => ['key' => self::KEY, 'value' => '900']],
            ['action' => 'batch.activate', 'params' => []],
            ['action' => 'batch.activate', 'params' => ['batchId' => '00000000-0000-0000-0000-000000000000']],
        ];
        foreach ($bad as $i => $body) {
            $this->assertContains($this->api('POST', '/approvals', $body)->status(), [404, 422], "caso $i");
        }
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM approvals')->n);
    }

    public function test_roles_el_auditor_solo_mira_y_el_operador_no_toca_parametros(): void
    {
        $this->login($this->makeAdmin('auditor'));
        $this->assertSame(200, $this->api('GET', '/approvals')->status());
        $this->assertSame(403, $this->api('POST', '/approvals', $this->settingParams())->status());
        $this->assertSame(403, $this->api('POST', '/approvals/1/approve', ['code' => '123456'])->status());
        $this->assertSame(403, $this->api('POST', '/approvals/1/execute')->status());

        $this->asUser($this->makeAdmin('operator'));
        $r = $this->api('POST', '/approvals', $this->settingParams());
        $this->assertSame(403, $r->status(), 'el operador no puede pedir cambios de parámetros');
        $this->assertSame('forbidden', $r->json('error.code'));
    }

    public function test_un_operador_no_aprueba_un_cambio_de_parametro_aunque_tenga_permiso_de_aprobar(): void
    {
        $a = $this->makeAdmin('superadmin');
        $op = $this->makeAdmin('operator');
        $this->login($a);
        $id = $this->request($this->settingParams());
        $this->asUser($op);
        $this->assertSame(403, $this->approve($id, $op)->status());
        $this->assertSame('pending', DB::table('approvals')->where('id', $id)->value('status'));
    }

    public function test_el_panel_avisa_si_hay_menos_de_dos_aprobadores(): void
    {
        $a = $this->makeAdmin('superadmin');
        $this->login($a);
        $this->assertTrue($this->api('GET', '/approvals')->json('fewApprovers'));
        $this->makeAdmin('operator');
        $this->assertFalse($this->api('GET', '/approvals')->json('fewApprovers'));
        $this->makeAdmin('auditor');
    }

    public function test_activar_un_lote_con_control_dual_espera_la_baliza_y_se_puede_reintentar(): void
    {
        $f = new Fixtures;
        $this->app->instance(BeaconClient::class, $f->beacon);
        $batch = $f->committed();
        $round = (int) DB::table('batches')->where('id', $batch)->value('beacon_round');

        $op = $this->makeAdmin('operator');
        $boss = $this->makeAdmin('superadmin');
        $this->login($op);
        $id = $this->request(['action' => 'batch.activate', 'params' => ['batchId' => $batch]]);
        $this->asUser($boss);
        $this->approve($id, $boss)->assertNoContent();

        $this->asUser($op);
        $r = $this->api('POST', "/approvals/$id/execute");
        $this->assertSame('beaconNotReady', $r->json('error.code'));
        $this->assertSame('approved', DB::table('approvals')->where('id', $id)->value('status'), 'sigue vigente para reintentar');
        $this->assertSame('committed', DB::table('batches')->where('id', $batch)->value('state'));

        $f->beacon->publish($round);
        $this->api('POST', "/approvals/$id/execute")->assertNoContent();
        $this->assertSame('active', DB::table('batches')->where('id', $batch)->value('state'));
        $this->assertSame('executed', DB::table('approvals')->where('id', $id)->value('status'));
    }

    public function test_no_se_puede_pedir_activar_un_lote_que_no_esta_comprometido(): void
    {
        $f = new Fixtures;
        $draft = $f->draftWithCodes();
        $this->login($this->makeAdmin('operator'));
        $this->assertSame(422, $this->api('POST', '/approvals', ['action' => 'batch.activate', 'params' => ['batchId' => $draft]])->status());
    }

    public function test_el_cambio_directo_de_un_parametro_de_seguridad_sigue_bloqueado(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $this->assertSame(409, $this->api('PUT', '/settings/'.self::KEY, ['value' => 900])->status());
    }
}
