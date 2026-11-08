<?php

namespace Tests\Feature\Demo;

use App\Domain\Admin\SecretBox;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoSwitch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\HostTokens;
use Tests\Support\RedeemFixtures;

/** La muestra de cara al usuario: sesión simulada, compra, sorteo y barrera de canje (SPEC-006 M3; CA-1, CA-5…CA-8, T-D1…T-D5, T-D9). */
final class DemoShopTest extends DbTestCase
{
    private const ISSUER = 'standalone-dev';

    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure([self::ISSUER => ['alg' => 'HS256', 'key' => base64_encode(HostTokens::SECRET)]]);
        config(['gamification.demo.allowed' => true]);
    }

    /** @return array<string, mixed> */
    private function demoConfig(array $override = []): array
    {
        $c = DemoConfig::defaults();
        $c['user']['initialBalance'] = 1000;
        $c['credit'] = array_replace($c['credit'], ['minPurchase' => 10_000, 'maxCodesPerPurchase' => 3, 'maxCodesPerSession' => 10], $override['credit'] ?? []);
        $c['mix'] = [
            'roulette' => ['prizes' => [['type' => 'money', 'amount' => 5000, 'count' => 4]], 'noPrizeCount' => 16],
            'scratch' => ['prizes' => [['type' => 'money', 'amount' => 10000, 'count' => 4]], 'noPrizeCount' => 16],
        ];

        return DemoConfig::validate($c);
    }

    /** Configura, siembra y enciende la muestra. */
    private function arm(array $override = []): void
    {
        $cfg = $this->demoConfig($override);
        $switch = new DemoSwitch;
        $switch->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $switch->enable('test');
    }

    /** @return array<string, mixed> */
    private function openSession(?string $resume = null): array
    {
        $r = $this->postJson('/v1/demo/session', $resume === null ? [] : ['resume' => $resume]);
        $r->assertOk();

        return $r->json();
    }

    /** @param list<array{productId: string, quantity: int}> $items */
    private function buy(array $session, array $items, string $key = 'compra-0001', int $expect = 200): \Illuminate\Testing\TestResponse
    {
        $r = $this->postJson('/v1/demo/purchase', ['items' => $items], ['Authorization' => 'Bearer '.$session['token'], 'Idempotency-Key' => $key]);
        $this->assertSame($expect, $r->status(), $r->getContent());

        return $r;
    }

    private function counts(): array
    {
        return [
            'orders' => (int) DB::selectOne('SELECT count(*) AS n FROM demo_orders')->n,
            'ledger' => (int) DB::selectOne('SELECT count(*) AS n FROM demo_wallet_ledger')->n,
            'assigned' => (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned'")->n,
        ];
    }

    // --- Apagada / encendida ------------------------------------------------------------------------------------------

    public function test_apagada_el_estado_dice_false_y_sesion_y_compra_son_404(): void
    {
        $this->getJson('/v1/demo/status')->assertOk()->assertExactJson(['enabled' => false]);
        $this->postJson('/v1/demo/session')->assertStatus(404);
        $this->postJson('/v1/demo/purchase', ['items' => []], HostTokens::bearer())->assertStatus(404);
    }

    public function test_encendida_el_estado_dice_true_y_al_apagar_o_caducar_vuelve_a_404(): void
    {
        $this->arm();
        $this->getJson('/v1/demo/status')->assertOk()->assertExactJson(['enabled' => true]);
        $s = $this->openSession();
        (new DemoSwitch)->disable('test');
        $this->getJson('/v1/demo/status')->assertExactJson(['enabled' => false]);
        $this->postJson('/v1/demo/session')->assertStatus(404);
        $this->buy($s, [['productId' => 'recarga-20', 'quantity' => 1]], expect: 404);

        (new DemoSwitch)->enable('test');
        Carbon::setTestNow(Carbon::now()->addHours(25));
        $this->postJson('/v1/demo/session')->assertStatus(404);
        $this->getJson('/v1/demo/status')->assertExactJson(['enabled' => false]);
    }

    // --- Sesión --------------------------------------------------------------------------------------------------------

    public function test_la_sesion_entrega_un_token_valido_el_catalogo_las_reglas_y_el_saldo_inicial(): void
    {
        $this->arm();
        $s = $this->openSession();
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,32}\.[0-9a-f]{24}$/', $s['subject']);
        $this->assertSame(self::ISSUER.':'.$s['subject'], $s['userId']);
        $this->assertSame(600, $s['expiresIn']);
        $this->assertSame('Cliente de muestra', $s['displayName']);
        $this->assertSame(1000, $s['balance']);
        $this->assertSame([['id' => 'recarga-20', 'name' => 'Recarga $20.000', 'price' => 20000], ['id' => 'recarga-50', 'name' => 'Recarga $50.000', 'price' => 50000]], $s['products']);
        $this->assertEquals(['balancePercent' => 10, 'minPurchase' => 10000, 'codeStepAmount' => 20000, 'maxCodesPerPurchase' => 3, 'maxCodesPerSession' => 10], $s['rules']); // jsonb reordena claves
        $this->assertFalse($s['resumed']);
        // El token pasa por el verificador real de la API.
        $this->getJson('/v1/balance', ['Authorization' => 'Bearer '.$s['token']])->assertOk()->assertJsonPath('amount', 1000);
    }

    public function test_el_saldo_inicial_del_entorno_no_se_suma_a_las_sesiones_de_la_muestra(): void
    {
        $this->arm();
        config(['gamification.demo.initial_balance' => 777]);
        $s = $this->openSession();
        $this->assertSame(1000, $s['balance'], 'solo el saldo inicial que fijó el panel');
        $this->getJson('/v1/balance', ['Authorization' => 'Bearer '.$s['token']])->assertJsonPath('amount', 1000);
        // Un usuario cualquiera (no de la muestra) sí conserva el saldo inicial del entorno.
        $this->getJson('/v1/balance', HostTokens::bearer('maria'))->assertJsonPath('amount', 777);
    }

    public function test_el_token_de_la_sesion_respeta_la_vida_maxima_del_sistema(): void
    {
        $this->arm();
        (new \App\Domain\Settings\Settings)->set('token.max_lifetime_seconds', 120, 'test');
        $s = $this->openSession();
        $this->assertSame(120, $s['expiresIn']);
        $this->getJson('/v1/balance', ['Authorization' => 'Bearer '.$s['token']])->assertOk();
    }

    public function test_cada_sesion_es_un_usuario_distinto_y_reanudar_conserva_el_saldo(): void
    {
        $this->arm();
        $a = $this->openSession();
        $b = $this->openSession();
        $this->assertNotSame($a['subject'], $b['subject']);

        $this->buy($a, [['productId' => 'recarga-50', 'quantity' => 1]]);
        $again = $this->openSession($a['subject']);
        $this->assertTrue($again['resumed']);
        $this->assertSame($a['subject'], $again['subject']);
        $this->assertSame(1000 + 5000, $again['balance']);
        $this->assertSame(1000, $this->openSession($b['subject'])['balance'], 'la otra sesión no ve esa carga');

        foreach (['muestra.' . str_repeat('0', 24), 'basura', ['x'], 123] as $unknown) {
            $s = $this->openSession(is_string($unknown) ? $unknown : null);
            $this->assertFalse($s['resumed']);
            $this->assertNotSame($unknown, $s['subject']);
        }
        $this->assertSame(1000, (int) DB::selectOne("SELECT amount FROM demo_wallet_ledger WHERE kind = 'initial' AND user_id = ?", [self::ISSUER.':'.$a['subject']])->amount);
    }

    // --- Compra --------------------------------------------------------------------------------------------------------

    public function test_la_compra_carga_el_saldo_segun_la_regla_y_entrega_codigos_sorteados_sin_revelar_el_premio(): void
    {
        $this->arm();
        $s = $this->openSession();
        $r = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]])->json();

        $this->assertSame(50000, $r['total']);
        $this->assertSame(5000, $r['credited']);
        $this->assertSame(1000 + 5000, $r['balance']);
        $this->assertFalse($r['replayed']);
        $this->assertFalse($r['capReached']);
        $this->assertCount(2, $r['codes'], 'floor(50000 / 20000) = 2');
        foreach ($r['codes'] as $c) {
            $this->assertSame(['code', 'game'], array_keys($c), 'ni descripción ni premio');
            $this->assertContains($c['game'], ['roulette', 'scratch']);
            $this->assertMatchesRegularExpression('/^[A-Z]+-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{3}$/', $c['code']);
        }
        $this->assertCount(2, array_unique(array_column($r['codes'], 'code')));

        $userId = self::ISSUER.':'.$s['subject'];
        $rows = DB::select("SELECT state, assigned_to, order_id FROM demo_pool WHERE order_id = ?", [$r['orderId']]);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['assigned', $userId], [$row->state, $row->assigned_to]);
        }
        $this->assertSame(5000, (int) DB::selectOne("SELECT amount FROM demo_wallet_ledger WHERE kind = 'purchase_credit' AND order_id = ?", [$r['orderId']])->amount);
        $this->getJson('/v1/balance', ['Authorization' => 'Bearer '.$s['token']])->assertJsonPath('amount', 6000);
    }

    public function test_la_regla_se_calcula_con_la_configuracion_vigente_sin_desplegar(): void
    {
        $this->arm();
        $s = $this->openSession();
        $switch = new DemoSwitch;
        $cfg = $switch->row()['config'];
        $cfg['credit'] = ['balancePercent' => 25, 'minPurchase' => 0, 'codeStepAmount' => 10000, 'maxCodesPerPurchase' => 5, 'maxCodesPerSession' => 10];
        $cfg['products'][0]['price'] = 30000;
        $switch->saveConfig(DemoConfig::validate($cfg), 'test');

        $r = $this->buy($s, [['productId' => 'recarga-20', 'quantity' => 2]])->json();
        $this->assertSame(60000, $r['total'], 'precio nuevo del servidor');
        $this->assertSame(15000, $r['credited']);
        $this->assertCount(5, $r['codes'], 'min(floor(60000/10000)=6, tope por compra 5)');
    }

    public function test_el_total_no_alcanza_para_un_codigo_pero_si_carga_saldo(): void
    {
        $this->arm(['credit' => ['codeStepAmount' => 60000]]);
        $s = $this->openSession();
        $r = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]])->json();
        $this->assertSame([], $r['codes']);
        $this->assertSame(5000, $r['credited']);
        $this->assertFalse($r['capReached']);
    }

    public function test_por_debajo_del_minimo_se_rechaza_sin_efectos(): void
    {
        $this->arm(['credit' => ['minPurchase' => 30000]]);
        $s = $this->openSession();
        $before = $this->counts();
        $r = $this->buy($s, [['productId' => 'recarga-20', 'quantity' => 1]], expect: 422);
        $this->assertSame('invalidPurchase', $r->json('error.code'));
        $this->assertSame($before, $this->counts());
    }

    /** @return array<string, array{0: mixed}> */
    public static function badItems(): array
    {
        return [
            'sin líneas' => [[]], 'no es lista' => [['productId' => 'recarga-20', 'quantity' => 1]], 'texto' => ['recarga-20'],
            'producto desconocido' => [[['productId' => 'nada', 'quantity' => 1]]],
            'cantidad cero' => [[['productId' => 'recarga-20', 'quantity' => 0]]],
            'cantidad sobre el tope' => [[['productId' => 'recarga-20', 'quantity' => 11]]],
            'cantidad decimal' => [[['productId' => 'recarga-20', 'quantity' => 1.5]]],
            'cantidad como texto' => [[['productId' => 'recarga-20', 'quantity' => '1']]],
            'producto repetido' => [[['productId' => 'recarga-20', 'quantity' => 1], ['productId' => 'recarga-20', 'quantity' => 1]]],
            'precio impuesto por el cliente' => [[['productId' => 'recarga-20', 'quantity' => 1, 'price' => 1]]],
            'línea sin cantidad' => [[['productId' => 'recarga-20']]],
            'demasiadas líneas' => [array_map(fn ($i) => ['productId' => 'recarga-20', 'quantity' => 1], range(1, 6))],
            'línea que no es objeto' => [['recarga-20']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badItems')]
    public function test_una_compra_malformada_se_rechaza_sin_efectos(mixed $items): void
    {
        $this->arm();
        $s = $this->openSession();
        $before = $this->counts();
        $r = $this->postJson('/v1/demo/purchase', ['items' => $items], ['Authorization' => 'Bearer '.$s['token'], 'Idempotency-Key' => 'compra-0001']);
        $this->assertSame(422, $r->status(), $r->getContent());
        $this->assertSame('invalidPurchase', $r->json('error.code'));
        $this->assertSame($before, $this->counts());
    }

    public function test_sin_clave_de_idempotencia_o_con_una_mala_es_400(): void
    {
        $this->arm();
        $s = $this->openSession();
        $items = ['items' => [['productId' => 'recarga-20', 'quantity' => 1]]];
        $this->postJson('/v1/demo/purchase', $items, ['Authorization' => 'Bearer '.$s['token']])->assertStatus(400)->assertJsonPath('error.code', 'idempotencyKeyRequired');
        $this->postJson('/v1/demo/purchase', $items, ['Authorization' => 'Bearer '.$s['token'], 'Idempotency-Key' => 'corta'])->assertStatus(400);
        $this->assertSame(0, $this->counts()['orders']);
    }

    // --- Idempotencia --------------------------------------------------------------------------------------------------

    public function test_repetir_la_misma_compra_con_la_misma_clave_devuelve_lo_mismo_y_no_carga_ni_sortea_otra_vez(): void
    {
        $this->arm();
        $s = $this->openSession();
        $first = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]], 'clave-igual-1')->json();
        $before = $this->counts();

        $second = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]], 'clave-igual-1')->json();
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['orderId'], $second['orderId']);
        $this->assertSame($first['codes'], $second['codes'], 'los mismos códigos, en el mismo orden');
        $this->assertSame($first['credited'], $second['credited']);
        $this->assertSame($first['balance'], $second['balance'], 'sin doble carga');
        $this->assertSame($before, $this->counts());
    }

    public function test_el_orden_de_las_lineas_no_cambia_la_huella_de_la_compra(): void
    {
        $this->arm();
        $s = $this->openSession();
        $a = [['productId' => 'recarga-20', 'quantity' => 1], ['productId' => 'recarga-50', 'quantity' => 1]];
        $first = $this->buy($s, $a, 'clave-orden-1')->json();
        $again = $this->buy($s, array_reverse($a), 'clave-orden-1')->json();
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['orderId'], $again['orderId']);
    }

    public function test_la_misma_clave_con_otra_compra_es_un_error_de_uso(): void
    {
        $this->arm();
        $s = $this->openSession();
        $this->buy($s, [['productId' => 'recarga-20', 'quantity' => 1]], 'clave-igual-2');
        $before = $this->counts();
        $r = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]], 'clave-igual-2', 422);
        $this->assertSame('idempotencyKeyReuse', $r->json('error.code'));
        $this->assertSame($before, $this->counts());
    }

    public function test_la_clave_es_por_usuario_otra_persona_con_la_misma_clave_compra_aparte(): void
    {
        $this->arm();
        $a = $this->openSession();
        $b = $this->openSession();
        $ra = $this->buy($a, [['productId' => 'recarga-50', 'quantity' => 1]], 'clave-comun-1')->json();
        $rb = $this->buy($b, [['productId' => 'recarga-50', 'quantity' => 1]], 'clave-comun-1')->json();
        $this->assertNotSame($ra['orderId'], $rb['orderId']);
        $this->assertFalse($rb['replayed']);
        $this->assertEmpty(array_intersect(array_column($ra['codes'], 'code'), array_column($rb['codes'], 'code')), 'nunca el mismo código a dos personas');
    }

    // --- Topes y agotamiento ------------------------------------------------------------------------------------------

    public function test_el_tope_por_sesion_limita_los_codigos_pero_sigue_cargando_saldo(): void
    {
        $this->arm(['credit' => ['maxCodesPerSession' => 3]]);
        $s = $this->openSession();
        $first = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 2]], 'compra-tope-1')->json();
        $this->assertCount(3, $first['codes'], 'min(5, tope por compra 3)');
        $this->assertFalse($first['capReached']);

        $second = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]], 'compra-tope-2')->json();
        $this->assertSame([], $second['codes'], 'ya llegó al tope de la sesión');
        $this->assertTrue($second['capReached']);
        $this->assertSame(5000, $second['credited'], 'el saldo se carga igual');
        $this->assertSame(3, (int) DB::selectOne('SELECT COALESCE(SUM(codes_granted), 0) AS n FROM demo_orders')->n);
    }

    public function test_el_tope_por_sesion_es_por_persona_no_global(): void
    {
        $this->arm(['credit' => ['maxCodesPerSession' => 2]]);
        $a = $this->openSession();
        $b = $this->openSession();
        $this->assertCount(2, $this->buy($a, [['productId' => 'recarga-50', 'quantity' => 1]], 'compra-a-0001')->json('codes'));
        $this->assertCount(2, $this->buy($b, [['productId' => 'recarga-50', 'quantity' => 1]], 'compra-b-0001')->json('codes'));
    }

    public function test_sin_codigos_suficientes_se_rechaza_entera_y_no_deja_nada_a_medias(): void
    {
        $this->arm();
        $s = $this->openSession();
        DB::update("UPDATE demo_pool SET state = 'retired' WHERE id NOT IN (SELECT id FROM demo_pool WHERE state = 'available' ORDER BY id LIMIT 1)");
        $before = $this->counts();
        $r = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]], 'compra-agotada', 409);
        $this->assertSame('demoPoolExhausted', $r->json('error.code'));
        $this->assertSame($before, $this->counts(), 'ni orden, ni saldo, ni código asignado');
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n);
        $this->assertSame(1000, $this->openSession($s['subject'])['balance']);
    }

    public function test_solo_las_sesiones_de_la_muestra_pueden_comprar(): void
    {
        $this->arm();
        $items = ['items' => [['productId' => 'recarga-20', 'quantity' => 1]]];
        $headers = fn (string $iss, string $sub) => ['Authorization' => 'Bearer '.\Firebase\JWT\JWT::encode(
            ['iss' => $iss, 'sub' => $sub, 'aud' => HostTokens::AUDIENCE, 'iat' => time(), 'exp' => time() + 300], HostTokens::SECRET, 'HS256',
        ), 'Idempotency-Key' => 'compra-ajena-1'];
        // Usuario cualquiera del emisor abierto, host real, y sujeto con forma casi correcta.
        foreach ([[self::ISSUER, 'maria'], [HostTokens::ISSUER, 'muestra.'.str_repeat('a', 24)], [self::ISSUER, 'muestra.'.str_repeat('a', 23)], [self::ISSUER, 'Muestra.'.str_repeat('a', 24)]] as [$iss, $sub]) {
            $r = $this->postJson('/v1/demo/purchase', $items, $headers($iss, $sub));
            $this->assertSame(403, $r->status(), "$iss $sub");
            $this->assertSame('demoSessionInvalid', $r->json('error.code'));
        }
        $this->postJson('/v1/demo/purchase', $items, ['Idempotency-Key' => 'compra-ajena-1'])->assertStatus(401);
        $this->assertSame(0, $this->counts()['orders']);
    }

    // --- Barrera de canje (T-D1, T-D9) --------------------------------------------------------------------------------

    private function redeem(array $session, string $code, string $key = 'canje-0001'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/v1/codes/redeem', ['code' => $code], ['Authorization' => 'Bearer '.$session['token'], 'Idempotency-Key' => $key]);
    }

    public function test_quien_recibe_un_codigo_lo_canjea_y_obtiene_el_premio_predefinido(): void
    {
        $this->arm();
        $s = $this->openSession();
        $r = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]])->json();
        foreach ($r['codes'] as $i => $c) {
            $desc = DB::selectOne("SELECT description FROM demo_pool WHERE order_id = ? AND assigned_to = ? ORDER BY id OFFSET ? LIMIT 1", [$r['orderId'], self::ISSUER.':'.$s['subject'], $i]);
            $res = $this->redeem($s, $c['code'], 'canje-000'.$i);
            $res->assertOk();
            $this->assertSame($c['game'], $res->json('campaign.gameType'));
            $this->assertNotNull($desc);
        }
    }

    public function test_el_codigo_de_otra_persona_se_rechaza_igual_que_uno_inexistente(): void
    {
        $this->arm();
        $a = $this->openSession();
        $b = $this->openSession();
        $code = $this->buy($a, [['productId' => 'recarga-50', 'quantity' => 1]])->json('codes.0.code');

        $stolen = $this->redeem($b, $code);
        $unknown = $this->redeem($b, 'RULETA-ZZZZ-ZZZZ-ZZZ', 'canje-0002');
        $this->assertSame($unknown->status(), $stolen->status());
        $this->assertSame($unknown->json('error.code'), $stolen->json('error.code'));
        $this->assertSame('invalidCode', $stolen->json('error.code'));
        $this->assertNull(DB::selectOne('SELECT 1 AS x FROM codes WHERE redeemed_at IS NOT NULL'), 'el código no se gastó');
        $this->redeem($a, $code, 'canje-0003')->assertOk();
    }

    public function test_un_codigo_del_pool_sin_asignar_no_lo_canjea_nadie_ni_siquiera_conociendolo(): void
    {
        $this->arm();
        $s = $this->openSession();
        $row = DB::selectOne("SELECT encode(code_enc, 'hex') AS enc, encode(code_hash, 'hex') AS hash FROM demo_pool WHERE state = 'available' LIMIT 1");
        $plain = (new SecretBox)->open(hex2bin($row->enc), DemoSeeder::sealContext($row->hash));
        $this->redeem($s, $plain)->assertStatus(422)->assertJsonPath('error.code', 'invalidCode');
        // Tampoco un usuario de un host real.
        $this->postJson('/v1/codes/redeem', ['code' => $plain], HostTokens::bearer('maria') + ['Idempotency-Key' => 'canje-0009'])->assertStatus(422)->assertJsonPath('error.code', 'invalidCode');
    }

    public function test_un_codigo_asignado_pero_retirado_ya_no_se_canjea(): void
    {
        $this->arm();
        $s = $this->openSession();
        $code = $this->buy($s, [['productId' => 'recarga-50', 'quantity' => 1]])->json('codes.0.code');
        DB::update("UPDATE demo_pool SET state = 'retired'");
        $this->redeem($s, $code)->assertStatus(422)->assertJsonPath('error.code', 'invalidCode');
    }

    public function test_el_emisor_abierto_nunca_llega_a_una_campania_real_aunque_el_servidor_la_tenga(): void
    {
        $this->arm();
        // Se simula la configuración equivocada: una campaña real activa DESPUÉS de encender la muestra.
        $real = (new RedeemFixtures)->activeBatch();
        $s = $this->openSession();
        $this->redeem($s, $real['codes'][0])->assertStatus(422)->assertJsonPath('error.code', 'invalidCode');
        // El mismo código lo canjea sin problema un usuario de un host real (el código es válido: lo que falla es el emisor).
        $this->postJson('/v1/codes/redeem', ['code' => $real['codes'][0]], HostTokens::bearer('maria') + ['Idempotency-Key' => 'canje-real-1'])->assertOk();
    }

    public function test_un_token_del_emisor_abierto_con_cualquier_sujeto_tampoco_llega_a_una_campania_real(): void
    {
        $this->arm();
        $real = (new RedeemFixtures)->activeBatch();
        $r = $this->postJson('/v1/codes/redeem', ['code' => $real['codes'][1]], ['Authorization' => 'Bearer '.\Firebase\JWT\JWT::encode(
            ['iss' => self::ISSUER, 'sub' => 'otro-sujeto', 'aud' => HostTokens::AUDIENCE, 'iat' => time(), 'exp' => time() + 300], HostTokens::SECRET, 'HS256',
        ), 'Idempotency-Key' => 'canje-real-2']);
        $r->assertStatus(422)->assertJsonPath('error.code', 'invalidCode');
    }
}
