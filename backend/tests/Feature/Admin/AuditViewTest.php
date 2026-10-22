<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditAnchor;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/** Visor de la bitácora del panel (SPEC-003 RF-38): listado, verificación por tramos y ancla. */
final class AuditViewTest extends AdminTestCase
{
    private AuditLog $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new AuditLog;
    }

    /** @return list<int> ids de las entradas agregadas */
    private function entries(int $n, string $action = 'prueba.evento'): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = $this->log->append('admin:1', $i % 2 === 0 ? $action : 'prueba.otro', 'cosa', 'c-'.$i, ['n' => $i]);
        }

        return $ids;
    }

    public function test_todos_los_roles_leen_y_pagina_por_clave_de_la_mas_nueva_a_la_mas_vieja(): void
    {
        $ids = $this->entries(5);
        foreach (['auditor', 'operator', 'superadmin'] as $role) {
            $this->login($this->makeAdmin($role));
            $this->api('GET', '/audit')->assertOk();
        }
        $first = $this->api('GET', '/audit?perPage=2')->assertOk()->json();
        $page1 = array_column($first['items'], 'id');
        $this->assertCount(2, $page1);
        $this->assertGreaterThan($page1[1], $page1[0], 'la más nueva primero');
        $this->assertNotNull($first['nextBeforeId']);
        $second = $this->api('GET', '/audit?perPage=2&beforeId='.$first['nextBeforeId'])->json();
        $this->assertSame([], array_intersect($page1, array_column($second['items'], 'id')), 'sin repetidos entre páginas');
        $this->assertLessThan($first['nextBeforeId'], max(array_column($second['items'], 'id')));

        // Recorrerla entera por páginas devuelve cada entrada exactamente una vez.
        $seen = [];
        $before = null;
        do {
            $r = $this->api('GET', '/audit?perPage=3'.($before ? "&beforeId=$before" : ''))->json();
            array_push($seen, ...array_column($r['items'], 'id'));
            $before = $r['nextBeforeId'];
        } while ($before !== null);
        $this->assertSame(count($seen), count(array_unique($seen)));
        $this->assertSame((int) DB::table('audit_log')->count(), count($seen));
        $this->assertContains($ids[0], $seen);
    }

    public function test_filtra_por_accion_sujeto_actor_y_fecha_y_muestra_los_datos(): void
    {
        $this->login($this->makeAdmin('auditor'));
        $this->entries(4);
        $r = $this->api('GET', '/audit?action=prueba.otro&subjectType=cosa')->assertOk()->json('items');
        $this->assertCount(2, $r);
        $this->assertSame(['prueba.otro', 'admin:1', 'cosa'], [$r[0]['action'], $r[0]['actor'], $r[0]['subjectType']]);
        $this->assertSame(['n' => 3], $r[0]['data']);
        $this->assertCount(1, $this->api('GET', '/audit?subjectId=c-0&action=prueba.evento')->json('items'));
        $this->assertCount(0, $this->api('GET', '/audit?actor=admin:999')->json('items'));
        $this->assertCount(0, $this->api('GET', '/audit?from='.urlencode('2999-01-01T00:00:00Z'))->json('items'));
        $this->assertGreaterThanOrEqual(4, count($this->api('GET', '/audit?to='.urlencode('2999-01-01T00:00:00Z'))->json('items')));
    }

    public function test_parametros_mal_formados_son_422_y_el_tamano_de_pagina_esta_acotado(): void
    {
        $this->login($this->makeAdmin('auditor'));
        foreach (['beforeId=abc', 'beforeId=0', 'perPage=-1', 'from=ayer', 'to=nunca'] as $q) {
            $this->assertSame(422, $this->api('GET', "/audit?$q")->status(), $q);
        }
        $this->assertSame(200, $this->api('GET', '/audit?perPage=99999')->json('perPage'));
    }

    public function test_sin_sesion_o_sin_permiso_no_se_lee(): void
    {
        $this->entries(1);
        $this->assertSame(401, $this->api('GET', '/audit')->status());
        $this->assertSame(401, $this->api('GET', '/audit/verify')->status());
        $this->assertSame(401, $this->api('GET', '/audit/anchor')->status());
    }

    public function test_la_verificacion_por_tramos_recorre_toda_la_cadena_y_termina(): void
    {
        $this->login($this->makeAdmin('auditor'));
        $this->entries(5);
        $total = (int) DB::table('audit_log')->count();

        $after = 0;
        $checked = 0;
        $calls = 0;
        do {
            $r = $this->api('GET', "/audit/verify?afterId=$after&limit=2")->assertOk()->json();
            $this->assertNull($r['brokenAt']);
            $checked += $r['checked'];
            $after = $r['lastId'];
            $calls++;
        } while (! $r['done'] && $calls < 100);
        $this->assertSame($total, $checked);
        $this->assertGreaterThan(1, $calls, 'se recorrió por tramos');
        $this->assertSame((new AuditAnchor)->current()['head_hash'], $r['lastHash'], 'el último hash es el del ancla');
    }

    public function test_detecta_una_entrada_alterada_en_el_tramo_donde_esta(): void
    {
        $this->login($this->makeAdmin('auditor'));
        $ids = $this->entries(6);
        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_no_update_delete');
        DB::update("UPDATE audit_log SET actor = 'admin:666' WHERE id = ?", [$ids[4]]);
        DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_no_update_delete');

        $beforeCount = (int) DB::table('audit_log')->where('id', '<', $ids[4])->count();
        $before = $this->api('GET', "/audit/verify?afterId=0&limit=$beforeCount")->json();
        $this->assertNull($before['brokenAt'], 'lo anterior sigue íntegro');
        $r = $this->api('GET', '/audit/verify?afterId='.$before['lastId'].'&limit=100')->assertOk()->json();
        $this->assertSame($ids[4], $r['brokenAt']);
        $this->assertTrue($r['done']);
        $this->assertSame($ids[4], (new AuditLog)->verifyChain(), 'coincide con la verificación completa');
    }

    public function test_un_afterid_inexistente_es_422_y_la_verificacion_se_limita_por_usuario(): void
    {
        $this->login($this->makeAdmin('auditor'));
        $this->entries(1);
        $this->assertSame(422, $this->api('GET', '/audit/verify?afterId=999999999')->status());
        $this->assertSame(422, $this->api('GET', '/audit/verify?limit=0')->status());

        DB::table('settings')->insert(['key' => 'admin.audit_verify_per_minute', 'value' => '2', 'updated_by' => 'test', 'updated_at' => now()]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->api('GET', '/audit/verify')->assertOk();
        $this->api('GET', '/audit/verify')->assertOk();
        $limited = $this->api('GET', '/audit/verify');
        $this->assertSame(429, $limited->status());
        $this->assertGreaterThan(0, $limited->json('error.retryAfter'));
    }

    public function test_el_ancla_es_la_del_ultimo_hash_y_y_su_id(): void
    {
        $this->login($this->makeAdmin('auditor'));
        $this->entries(2);
        $anchor = $this->api('GET', '/audit/anchor')->assertOk()->json('anchor');
        $this->assertSame((new AuditAnchor)->current()['head_hash'], $anchor['head_hash']);
        $this->assertSame((int) DB::table('audit_log')->max('id'), $anchor['head_id']);
    }
}
