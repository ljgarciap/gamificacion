<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoSwitch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;

/** Muestra real: configuración e interruptor (SPEC-006 M1; CA-1…CA-4, amenazas T-D1, T-D7, T-D8). */
final class DemoSwitchTest extends AdminTestCase
{
    private Fixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
        config([
            'gamification.demo.allowed' => true,
            'gamification.hosts.'.config('gamification.dev.issuer') => ['alg' => 'HS256', 'key' => base64_encode(str_repeat('k', 32))],
        ]);
    }

    private function superadmin(): array
    {
        $a = $this->makeAdmin('superadmin');
        $this->login($a);

        return $a;
    }

    /** Un lote de campaña demo con $n códigos en el pool (el contenido cifrado no importa en esta prueba). */
    private function seedPool(int $n = 2): string
    {
        $campaign = $this->f->campaign();
        DB::update('UPDATE campaigns SET is_demo = true WHERE id = ?', [$campaign]);
        $batch = $this->f->draft($campaign);
        for ($i = 0; $i < $n; $i++) {
            DB::insert("INSERT INTO demo_pool (batch_id, code_enc, code_hash, game, description) VALUES (?, decode('00','hex'), decode(?,'hex'), 'roulette', 'Ruleta: prueba')",
                [$batch, bin2hex(random_bytes(8))]);
        }

        return $batch;
    }

    private function saveConfig(?array $config = null): void
    {
        $this->assertSame(200, $this->api('PUT', '/demo/config', $config ?? DemoConfig::defaults())->status());
    }

    public function test_solo_el_superadmin_entra_y_sin_sesion_es_401(): void
    {
        $this->assertSame(401, $this->api('GET', '/demo')->status());
        foreach (['operator', 'auditor'] as $role) {
            $this->login($this->makeAdmin($role));
            foreach ([['GET', '/demo'], ['PUT', '/demo/config'], ['POST', '/demo/enable'], ['POST', '/demo/disable']] as [$m, $p]) {
                $this->assertSame(403, $this->api($m, $p, [])->status(), "$role $m $p");
            }
            $this->api('POST', '/auth/logout');
        }
        $this->assertFalse((new DemoSwitch)->isActive());
    }

    public function test_sin_configurar_muestra_los_valores_de_ejemplo_apagado(): void
    {
        $this->superadmin();
        $r = $this->api('GET', '/demo')->assertOk();
        $this->assertFalse($r->json('configured'));
        $this->assertFalse($r->json('status.enabled'));
        $this->assertSame(DemoConfig::defaults(), $r->json('config'));
        $this->assertSame(['available' => 0, 'assigned' => 0, 'retired' => 0], $r->json('pool'));
    }

    public function test_guardar_la_configuracion_sube_la_version_y_queda_en_la_bitacora(): void
    {
        $a = $this->superadmin();
        $this->saveConfig();
        $cfg = DemoConfig::defaults();
        $cfg['credit']['balancePercent'] = 15;
        $r = $this->api('PUT', '/demo/config', $cfg)->assertOk();
        $this->assertSame(2, $r->json('version'));
        $this->assertSame(15, $this->api('GET', '/demo')->json('config.credit.balancePercent'));
        $this->assertTrue($this->api('GET', '/demo')->json('configured'));
        $e = DB::selectOne("SELECT actor FROM audit_log WHERE action = 'demo.config_changed' ORDER BY id DESC LIMIT 1");
        $this->assertSame('admin:'.$a['id'], $e->actor);
        $this->assertNull((new AuditLog)->verifyChain());
    }

    /** @return array<string, array{0: callable(array): array}> */
    public static function badConfigs(): array
    {
        return [
            'clave desconocida' => [fn (array $c) => $c + ['extra' => 1]],
            'sin productos' => [function (array $c) { $c['products'] = []; return $c; }],
            'demasiados productos' => [function (array $c) { for ($i = 0; $i < 6; $i++) { $c['products'][] = ['id' => "p$i", 'name' => 'X', 'price' => 1000]; } return $c; }],
            'producto repetido' => [function (array $c) { $c['products'][1]['id'] = $c['products'][0]['id']; return $c; }],
            'precio cero' => [function (array $c) { $c['products'][0]['price'] = 0; return $c; }],
            'precio decimal' => [function (array $c) { $c['products'][0]['price'] = 1000.5; return $c; }],
            'precio como texto' => [function (array $c) { $c['products'][0]['price'] = '1000'; return $c; }],
            'precio sobre el techo' => [function (array $c) { $c['products'][0]['price'] = DemoConfig::MAX_AMOUNT + 1; return $c; }],
            'porcentaje mayor a 100' => [function (array $c) { $c['credit']['balancePercent'] = 101; return $c; }],
            'códigos por compra sobre el techo' => [function (array $c) { $c['credit']['maxCodesPerPurchase'] = DemoConfig::MAX_CODES_PER_PURCHASE + 1; return $c; }],
            'paso de código cero' => [function (array $c) { $c['credit']['codeStepAmount'] = 0; return $c; }],
            'juego desconocido' => [function (array $c) { $c['mix'] = ['ajedrez' => ['prizes' => [], 'noPrizeCount' => 1]]; return $c; }],
            'mezcla vacía' => [function (array $c) { $c['mix'] = []; return $c; }],
            'tipo de premio desconocido' => [function (array $c) { $c['mix']['roulette']['prizes'][0]['type'] = 'oro'; return $c; }],
            'premio en dinero de monto cero' => [function (array $c) { $c['mix']['roulette']['prizes'][0]['amount'] = 0; return $c; }],
            'pool sobre el techo' => [function (array $c) { $c['mix']['roulette']['noPrizeCount'] = DemoConfig::MAX_POOL; return $c; }],
            'juego sin nada' => [function (array $c) { $c['mix']['roulette'] = ['prizes' => [], 'noPrizeCount' => 0]; return $c; }],
            'prefijo de usuario inválido' => [function (array $c) { $c['user']['hostUserPrefix'] = 'Mayús cula'; return $c; }],
            'ttl cero' => [function (array $c) { $c['ttlHours'] = 0; return $c; }],
            'ttl sobre el techo' => [function (array $c) { $c['ttlHours'] = DemoConfig::MAX_TTL_HOURS + 1; return $c; }],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badConfigs')]
    public function test_una_configuracion_fuera_de_limites_se_rechaza_y_no_se_guarda(callable $mutate): void
    {
        $this->superadmin();
        $r = $this->api('PUT', '/demo/config', $mutate(DemoConfig::defaults()));
        $this->assertSame(422, $r->status(), $r->getContent());
        $this->assertSame('validationFailed', $r->json('error.code'));
        $this->assertSame(0, (new DemoSwitch)->row()['version']);
    }

    public function test_ttl_nulo_es_valido_y_se_guarda(): void
    {
        $this->superadmin();
        $cfg = DemoConfig::defaults();
        $cfg['ttlHours'] = null;
        $this->saveConfig($cfg);
        $this->assertNull((new DemoSwitch)->row()['config']['ttlHours']);
    }

    public function test_no_se_enciende_si_el_servidor_no_es_de_demostracion(): void
    {
        $this->superadmin();
        $this->saveConfig();
        $this->seedPool();
        config(['gamification.demo.allowed' => false]);
        $r = $this->api('POST', '/demo/enable');
        $this->assertSame(409, $r->status());
        $this->assertStringContainsString('GAMIF_DEMO_ALLOWED', $r->json('error.message'));
        $this->assertFalse((new DemoSwitch)->isActive());
    }

    public function test_no_se_enciende_con_el_saldo_de_un_host_real(): void
    {
        $this->superadmin();
        $this->saveConfig();
        $this->seedPool();
        config(['gamification.host.balance' => 'http']);
        $r = $this->api('POST', '/demo/enable');
        $this->assertSame(409, $r->status());
        $this->assertStringContainsString('GAMIF_BALANCE=stub', $r->json('error.message'));
        $this->assertFalse((new DemoSwitch)->isActive());
    }

    public function test_no_se_enciende_sin_configuracion_guardada(): void
    {
        $this->superadmin();
        $this->seedPool();
        $r = $this->api('POST', '/demo/enable');
        $this->assertSame(409, $r->status());
        $this->assertStringContainsString('configuración', $r->json('error.message'));
    }

    public function test_no_se_enciende_con_el_emisor_sin_configurar(): void
    {
        $this->superadmin();
        $this->saveConfig();
        $this->seedPool();
        config(['gamification.hosts' => []]);
        $this->assertSame(409, $this->api('POST', '/demo/enable')->status());
        config(['gamification.hosts.'.config('gamification.dev.issuer') => ['alg' => 'RS256', 'key' => 'x']]);
        $this->assertSame(409, $this->api('POST', '/demo/enable')->status());
    }

    public function test_no_se_enciende_con_el_pool_vacio_ni_con_todo_el_pool_asignado(): void
    {
        $this->superadmin();
        $this->saveConfig();
        $this->assertSame(409, $this->api('POST', '/demo/enable')->status());
        $this->seedPool(1);
        DB::update("UPDATE demo_pool SET state = 'assigned', assigned_to = 'u', assigned_at = now()");
        $this->assertSame(409, $this->api('POST', '/demo/enable')->status());
    }

    public function test_no_se_enciende_si_hay_una_campania_real_activa(): void
    {
        $this->superadmin();
        $this->saveConfig();
        $this->seedPool();
        $this->f->active();
        $r = $this->api('POST', '/demo/enable');
        $this->assertSame(409, $r->status());
        $this->assertStringContainsString('campaña', $r->json('error.message'));
        $this->assertFalse((new DemoSwitch)->isActive());
    }

    public function test_enciende_con_caducidad_y_apaga_dejando_rastro(): void
    {
        $a = $this->superadmin();
        $this->saveConfig();
        $this->seedPool();
        $this->assertSame(204, $this->api('POST', '/demo/enable')->status());
        $s = $this->api('GET', '/demo')->json('status');
        $this->assertTrue($s['enabled'] && $s['active']);
        $this->assertEqualsWithDelta(Carbon::now()->addHours(24)->getTimestamp(), Carbon::parse($s['enabledUntil'])->getTimestamp(), 5);

        $this->assertSame(204, $this->api('POST', '/demo/disable')->status());
        $s = $this->api('GET', '/demo')->json('status');
        $this->assertFalse($s['enabled'] || $s['active']);
        $this->assertNull($s['enabledUntil']);

        $actions = array_column(DB::select("SELECT action, actor FROM audit_log WHERE action IN ('demo.enabled','demo.disabled') ORDER BY id"), 'actor', 'action');
        $this->assertSame(['demo.enabled' => 'admin:'.$a['id'], 'demo.disabled' => 'admin:'.$a['id']], $actions);
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_caduca_sola_y_el_planificador_lo_persiste(): void
    {
        $this->superadmin();
        $this->saveConfig();
        $this->seedPool();
        $this->api('POST', '/demo/enable')->assertNoContent();
        $this->advance(24 * 3600 - 60);
        $this->assertTrue((new DemoSwitch)->isActive());
        $this->assertFalse((new DemoSwitch)->expireIfDue());
        $this->advance(120);
        $this->assertFalse((new DemoSwitch)->isActive(), 'vencido: inactivo aunque la fila diga enabled');
        $this->assertTrue((new DemoSwitch)->row()['enabled']);
        $this->assertTrue((new DemoSwitch)->expireIfDue());
        $this->assertFalse((new DemoSwitch)->row()['enabled']);
        $this->assertSame('system', DB::selectOne("SELECT actor FROM audit_log WHERE action = 'demo.disabled'")->actor);
    }

    public function test_sin_ttl_no_caduca(): void
    {
        $this->superadmin();
        $cfg = DemoConfig::defaults();
        $cfg['ttlHours'] = null;
        $this->saveConfig($cfg);
        $this->seedPool();
        $this->api('POST', '/demo/enable')->assertNoContent();
        $this->advance(30 * 24 * 3600);
        $this->assertTrue((new DemoSwitch)->isActive());
    }

    // --- La base impone lo que el código promete (el esquema no se fía solo de PHP) ---

    public function test_el_pool_rechaza_lotes_de_campanias_reales(): void
    {
        $real = $this->f->draft();
        $this->assertDbRejects(fn () => DB::insert("INSERT INTO demo_pool (batch_id, code_enc, code_hash, game, description) VALUES (?, decode('00','hex'), decode('aa','hex'), 'roulette', 'x')", [$real]), 'solo admite lotes de campañas de demostración');
    }

    public function test_el_pool_no_resucita_ni_cambia_codigos(): void
    {
        $this->seedPool(1);
        DB::update("UPDATE demo_pool SET state = 'assigned', assigned_to = 'u', assigned_at = now()");
        $this->assertDbRejects(fn () => DB::update("UPDATE demo_pool SET state = 'available', assigned_to = NULL, assigned_at = NULL"), 'no vuelve al pool');
        DB::update("UPDATE demo_pool SET state = 'retired'");
        $this->assertDbRejects(fn () => DB::update("UPDATE demo_pool SET state = 'assigned', assigned_to = 'u', assigned_at = now()"), 'no vuelve al pool');
        $this->assertDbRejects(fn () => DB::update("UPDATE demo_pool SET code_enc = decode('ff','hex')"), 'no se modifica');
    }

    public function test_el_libro_de_saldo_es_de_solo_agregar(): void
    {
        $order = '00000000-0000-4000-8000-000000000001';
        DB::insert("INSERT INTO demo_orders (id, user_id, items, total, credited, codes_granted, idempotency_key, request_hash) VALUES (?, 'u', '[]'::jsonb, 1000, 100, 1, 'k', 'h')", [$order]);
        DB::insert("INSERT INTO demo_wallet_ledger (user_id, kind, amount, order_id) VALUES ('u', 'purchase_credit', 100, ?)", [$order]);
        $this->assertDbRejects(fn () => DB::update('UPDATE demo_wallet_ledger SET amount = 999'), 'solo agregar');
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM demo_wallet_ledger'), 'solo agregar');
        $this->assertDbRejects(fn () => DB::unprepared('TRUNCATE demo_wallet_ledger'), 'solo agregar');
    }

    public function test_la_orden_es_idempotente_por_usuario_y_clave(): void
    {
        $ins = fn (string $id, string $user, string $key) => DB::insert("INSERT INTO demo_orders (id, user_id, items, total, credited, codes_granted, idempotency_key, request_hash) VALUES (?, ?, '[]'::jsonb, 1000, 0, 0, ?, 'h')", [$id, $user, $key]);
        $ins('00000000-0000-4000-8000-000000000001', 'u', 'k');
        $ins('00000000-0000-4000-8000-000000000002', 'otro', 'k');
        $this->assertDbRejects(fn () => $ins('00000000-0000-4000-8000-000000000003', 'u', 'k'), 'duplicate key');
    }

    public function test_solo_una_corrida_activa_a_la_vez(): void
    {
        DB::insert("INSERT INTO demo_runs (kind, requested_by) VALUES ('seed', 'a')");
        $this->assertDbRejects(fn () => DB::insert("INSERT INTO demo_runs (kind, requested_by) VALUES ('reset', 'a')"), 'duplicate key');
        DB::update("UPDATE demo_runs SET state = 'done'");
        DB::insert("INSERT INTO demo_runs (kind, requested_by) VALUES ('reset', 'a')");
        $this->assertSame(2, (int) DB::selectOne('SELECT count(*) AS n FROM demo_runs')->n);
    }

    public function test_la_base_no_permite_encender_sin_configuracion(): void
    {
        $this->assertDbRejects(fn () => DB::update('UPDATE demo_config SET enabled = true WHERE id = 1'), 'demo_config');
    }
}
