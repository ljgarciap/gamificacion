<?php

namespace Tests\Feature\Redemption;

use App\Domain\Audit\AuditLog;
use App\Domain\Balance\StubBalanceProvider;
use App\Domain\Redemption\Presentation;
use App\Domain\Redemption\RedeemFailure;
use App\Domain\Redemption\RedeemService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RedeemFixtures;

final class RedeemServiceTest extends DbTestCase
{
    private RedeemFixtures $rf;

    private RedeemService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rf = new RedeemFixtures;
        $this->svc = $this->rf->service();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function redeem(string $code, string $user = 'host-a:ana', string $key = 'clave-0000001'): array
    {
        return $this->svc->redeem($user, $key, $code, '10.0.0.1')->toArray();
    }

    private function failureOf(callable $f): RedeemFailure
    {
        try {
            $f();
        } catch (RedeemFailure $e) {
            return $e;
        }
        $this->fail('debía lanzar RedeemFailure');
    }

    /** Un código del lote cuyo premio sea $prizeId. */
    private function codeWith(array $batch, string $prizeId): string
    {
        $found = array_search($prizeId, $batch['prizeOf'], true);
        $this->assertNotFalse($found, "el lote no tiene un código con premio $prizeId");

        return $found;
    }

    // --- Camino feliz --------------------------------------------------------------------------------------------

    public function test_redimir_devuelve_el_premio_asignado_con_el_formato_que_espera_el_cliente(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
        $code = $this->codeWith($b, 'p50k');

        $r = $this->redeem($code);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $r['redemptionId']);
        $this->assertFalse($r['alreadyPlayed']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $r['playedAt']);
        $this->assertSame($b['campaign'], $r['campaign']['id']);
        $this->assertSame('roulette', $r['campaign']['gameType']);
        $this->assertSame('rejectOnly', $r['campaign']['usedCodeBehavior']);
        $this->assertSame('p50k', $r['outcome']['prize']['id']);
        $this->assertSame('money', $r['outcome']['prize']['type']);
        $this->assertSame(50000, $r['outcome']['prize']['amount']);
        $this->assertSame(0, $r['outcome']['extraSpins']);
    }

    public function test_el_resultado_de_la_ruleta_cae_en_un_segmento_que_muestra_ese_premio(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
        foreach (['p50k', 'p10k', 'none'] as $i => $prize) {
            $r = $this->redeem($this->codeWith($b, $prize), 'host-a:u'.$i, 'clave-000000'.$i);
            $segments = $r['campaign']['gameConfig']['segments'];
            $index = $r['outcome']['gameResult']['segmentIndex'];

            $this->assertGreaterThanOrEqual(4, count($segments));
            $this->assertSame($prize, $segments[$index]['id'], 'el segmento donde se detiene la ruleta muestra el premio ganado');
            $this->assertSame($prize, $r['outcome']['prize']['id']);
        }
    }

    public function test_el_raspa_devuelve_una_cuadricula_coherente_con_el_premio(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1), 'scratch');

        $win = $this->redeem($this->codeWith($b, 'p10k'), 'host-a:a', 'clave-0000001');
        $grid = $win['outcome']['gameResult']['grid'];
        $this->assertCount(9, $grid);
        $this->assertSame(3, count(array_keys($grid, 'p10k', true)), 'premio = 3 símbolos del premio');
        $this->assertSame(3, $win['campaign']['gameConfig']['rows']);
        $this->assertSame(3, $win['campaign']['gameConfig']['cols']);

        $lose = $this->redeem($this->codeWith($b, 'none'), 'host-a:b', 'clave-0000002');
        $this->assertCount(9, $lose['outcome']['gameResult']['grid']);
        $this->assertNotContains('none', $lose['outcome']['gameResult']['grid']);
        foreach (array_count_values($lose['outcome']['gameResult']['grid']) as $symbol => $n) {
            $this->assertLessThan(3, $n, "sin premio: el símbolo $symbol no puede formar un trío");
        }
    }

    public function test_los_objetos_vacios_siguen_siendo_objetos_en_el_json(): void
    {
        // Si `{}` pasara a `[]` el cliente Flutter fallaría al leerlo como mapa.
        $b = $this->rf->activeBatch();
        $json = $this->svc->redeem('host-a:ana', 'clave-0000001', $b['codes'][0], null)->json;
        $this->assertStringContainsString('"theme":{}', $json);
        $this->assertStringNotContainsString('"theme":[]', $json);
    }

    public function test_el_codigo_se_acepta_como_lo_escriba_el_usuario(): void
    {
        $b = $this->rf->activeBatch();
        $typed = strtolower(str_replace('-', ' ', $b['codes'][0]));
        $r = $this->redeem($typed);
        $this->assertSame($b['prizeOf'][$b['codes'][0]], $r['outcome']['prize']['id']);
    }

    public function test_cada_tipo_de_premio_llega_con_sus_datos(): void
    {
        $campaign = $this->rf->f->campaign('roulette');
        DB::table('prizes')->insert([
            ['campaign_id' => $campaign, 'prize_id' => 'cupon', 'type' => 'coupon', 'label' => 'Cupón 20%', 'amount' => null, 'meta' => json_encode(['coupon_code' => 'GIRA20', 'icon_key' => 'gift'])],
            ['campaign_id' => $campaign, 'prize_id' => 'pts', 'type' => 'points', 'label' => '250 puntos', 'amount' => 250, 'meta' => '{}'],
            ['campaign_id' => $campaign, 'prize_id' => 'extra', 'type' => 'extraSpin', 'label' => 'Gira de nuevo', 'amount' => null, 'meta' => json_encode(['extra_spins' => 2])],
        ]);
        DB::table('campaigns')->where('id', $campaign)->update(['game_config' => json_encode(['segments' => ['cupon', 'pts', 'extra', 'none', 'p10k', 'none', 'p50k', 'none']])]);
        $batch = $this->rf->f->lifecycle()->createDraft($campaign, 'L', [
            ['prize_id' => 'cupon', 'quantity' => 1], ['prize_id' => 'pts', 'quantity' => 1], ['prize_id' => 'extra', 'quantity' => 1],
            ['prize_id' => 'none', 'quantity' => 1], ['prize_id' => 'p10k', 'quantity' => 1], ['prize_id' => 'p50k', 'quantity' => 1],
        ], 'tester');
        $codes = $this->rf->generateCodes($batch);
        $this->rf->f->lifecycle()->commit($batch, 'tester');
        $this->rf->f->activate($batch);
        $map = $this->rf->prizeMap($batch, $codes);
        $byPrize = array_flip($map);

        $coupon = $this->redeem($byPrize['cupon'], 'host-a:1', 'clave-0000001')['outcome'];
        $this->assertSame(['id' => 'cupon', 'type' => 'coupon', 'label' => 'Cupón 20%', 'couponCode' => 'GIRA20', 'iconKey' => 'gift'], $coupon['prize']);
        $points = $this->redeem($byPrize['pts'], 'host-a:2', 'clave-0000002')['outcome'];
        $this->assertSame(250, $points['prize']['amount']);
        $extra = $this->redeem($byPrize['extra'], 'host-a:3', 'clave-0000003')['outcome'];
        $this->assertSame(2, $extra['extraSpins']);
        $none = $this->redeem($byPrize['none'], 'host-a:4', 'clave-0000004')['outcome'];
        $this->assertSame('none', $none['prize']['type']);
        $this->assertArrayNotHasKey('amount', $none['prize']);
    }

    // --- Efectos persistentes ---------------------------------------------------------------------------------------

    public function test_redimir_reclama_el_codigo_registra_la_redencion_y_el_aviso_de_acreditacion(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
        $r = $this->redeem($this->codeWith($b, 'p50k'));

        $this->assertSame(1, DB::table('codes')->where('batch_id', $b['batch'])->whereNotNull('redeemed_at')->count());
        $this->assertSame('host-a:ana', DB::table('codes')->whereNotNull('redeemed_by')->value('redeemed_by'));
        $this->assertSame(1, DB::table('redemptions')->count());
        $this->assertSame($r['redemptionId'], DB::table('redemptions')->value('id'));

        $outbox = DB::selectOne('SELECT status, attempts, payload::text AS p FROM prize_outbox');
        $this->assertSame('pending', $outbox->status);
        $payload = json_decode($outbox->p, true);
        $this->assertSame($r['redemptionId'], $payload['redemption_id'], 'el id de la redención es la clave de idempotencia del receptor');
        $this->assertSame(50000, $payload['prize']['amount']);
        $this->assertSame('host-a:ana', $payload['user_id']);
    }

    public function test_un_sin_premio_no_genera_aviso_de_acreditacion(): void
    {
        $b = $this->rf->activeBatch();
        $this->redeem($this->codeWith($b, 'none'));
        $this->assertSame(1, DB::table('redemptions')->count());
        $this->assertSame(0, DB::table('prize_outbox')->count());
    }

    public function test_la_bitacora_registra_la_redencion_sin_datos_personales_en_claro(): void
    {
        $b = $this->rf->activeBatch();
        $r = $this->redeem($b['codes'][0], 'host-a:persona-con-nombre');

        $entry = DB::selectOne("SELECT actor, data::text AS d FROM audit_log WHERE action = 'code.redeemed'");
        $this->assertStringNotContainsString('persona-con-nombre', $entry->actor.$entry->d, 'la bitácora es inmutable: no puede guardar el id del usuario en claro');
        $data = json_decode($entry->d, true);
        $this->assertSame($b['prizeOf'][$b['codes'][0]], $data['prize_id']);
        $this->assertSame(16, strlen($data['user_ref']));
        $this->assertSame('user:'.$data['user_ref'], $entry->actor);
        $this->assertNull((new AuditLog)->verifyChain());
        $this->assertNotNull($r);
    }

    public function test_la_referencia_de_usuario_es_estable_y_distinta_por_usuario(): void
    {
        $b = $this->rf->activeBatch();
        $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000001');
        $this->redeem($b['codes'][1], 'host-a:ana', 'clave-0000002');
        $this->redeem($b['codes'][2], 'host-a:beto', 'clave-0000003');
        $refs = DB::table('audit_log')->where('action', 'code.redeemed')->orderBy('id')->get()->map(fn ($r) => json_decode($r->data, true)['user_ref'])->all();
        $this->assertSame($refs[0], $refs[1]);
        $this->assertNotSame($refs[0], $refs[2]);
    }

    // --- Errores de código ---------------------------------------------------------------------------------------

    public function test_codigos_que_no_existen_o_estan_mal_escritos_son_invalidos(): void
    {
        $this->rf->activeBatch();
        $casos = [
            'vacío' => '', 'basura' => 'hola mundo', 'demasiado largo' => str_repeat('A', 200),
            'formato válido pero inexistente' => RedeemFixtures::unknownCode(),
            'dígito de verificación mal' => 'RULETA-0000-0000-000',
        ];
        $i = 0;
        foreach ($casos as $nombre => $code) {
            $e = $this->failureOf(fn () => $this->svc->redeem('host-a:u'.$i, 'clave-00000'.($i + 10), $code, '10.0.0.'.++$i));
            $this->assertSame(RedeemFailure::INVALID, $e->reason, $nombre);
            $this->assertSame(422, $e->httpStatus(), $nombre);
        }
        $this->assertSame(0, DB::table('redemptions')->count());
    }

    public function test_un_codigo_de_un_lote_aun_sin_activar_se_comporta_segun_su_estado(): void
    {
        $f = $this->rf->f;
        // borrador: el código ni siquiera "existe" para el público
        $draft = $f->draft();
        $draftCodes = $this->rf->generateCodes($draft);
        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:a', 'clave-0000001', $draftCodes[0]));
        $this->assertSame(RedeemFailure::INVALID, $e->reason);

        // comprometido pero no activo
        $f->lifecycle()->commit($draft, 'tester');
        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:a', 'clave-0000002', $draftCodes[0]));
        $this->assertSame(RedeemFailure::CAMPAIGN_INACTIVE, $e->reason);
        $this->assertSame(403, $e->httpStatus());
    }

    public function test_un_lote_cerrado_o_revelado_esta_vencido(): void
    {
        $closed = $this->rf->activeBatch();
        $this->rf->f->lifecycle()->close($closed['batch'], 'tester');
        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:a', 'clave-0000001', $closed['codes'][0]));
        $this->assertSame(RedeemFailure::EXPIRED, $e->reason);
        $this->assertSame(410, $e->httpStatus());

        $this->rf->f->lifecycle()->reveal($closed['batch'], 'tester');
        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:a', 'clave-0000002', $closed['codes'][1]));
        $this->assertSame(RedeemFailure::EXPIRED, $e->reason);
    }

    public function test_pasada_la_ventana_de_redencion_el_codigo_esta_vencido_aunque_el_lote_siga_activo(): void
    {
        $b = $this->rf->activeBatch(redeemableUntil: Carbon::now()->subMinute());
        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:a', 'clave-0000001', $b['codes'][0]));
        $this->assertSame(RedeemFailure::EXPIRED, $e->reason);
        $this->assertSame(0, DB::table('redemptions')->count());
    }

    // --- Un uso por código ------------------------------------------------------------------------------------------------

    public function test_otro_usuario_no_puede_usar_un_codigo_ya_redimido_ni_ve_su_premio(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1), usedCodeBehavior: 'showPrevious');
        $code = $this->codeWith($b, 'p50k');
        $this->redeem($code, 'host-a:ana', 'clave-0000001');

        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:beto', 'clave-0000002', $code));
        $this->assertSame(RedeemFailure::ALREADY_USED, $e->reason, 'aunque la campaña sea showPrevious, nunca se muestra el resultado de OTRA persona');
        $this->assertSame(409, $e->httpStatus());
        $this->assertSame(1, DB::table('redemptions')->count());
    }

    public function test_rejectOnly_el_mismo_usuario_con_otra_clave_recibe_codigo_usado(): void
    {
        $b = $this->rf->activeBatch(usedCodeBehavior: 'rejectOnly');
        $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000001');
        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:ana', 'clave-0000002', $b['codes'][0]));
        $this->assertSame(RedeemFailure::ALREADY_USED, $e->reason);
    }

    public function test_showPrevious_el_mismo_usuario_ve_su_resultado_anterior_sin_volver_a_jugar(): void
    {
        $b = $this->rf->activeBatch(usedCodeBehavior: 'showPrevious');
        $first = $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000001');
        $again = $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000002');

        $this->assertFalse($first['alreadyPlayed']);
        $this->assertTrue($again['alreadyPlayed']);
        $this->assertSame($first['redemptionId'], $again['redemptionId']);
        $this->assertSame($first['outcome'], $again['outcome'], 'mismo premio y misma presentación');
        $this->assertSame($first['playedAt'], $again['playedAt'], 'la fecha es la de la jugada original');
        $this->assertSame(1, DB::table('redemptions')->count());
        $this->assertSame(0, DB::table('prize_outbox')->count() - (int) (in_array($first['outcome']['prize']['type'], ['none'], true) ? 0 : 1), 'una sola acreditación');
    }

    // --- Idempotencia ---------------------------------------------------------------------------------------------------

    public function test_ca26_el_mismo_reintento_devuelve_exactamente_la_misma_respuesta_sin_acreditar_dos_veces(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
        $code = $this->codeWith($b, 'p50k');

        $first = $this->svc->redeem('host-a:ana', 'clave-0000001', $code);
        $retry = $this->svc->redeem('host-a:ana', 'clave-0000001', $code);
        $third = $this->svc->redeem('host-a:ana', 'clave-0000001', strtolower($code));

        $this->assertFalse($first->replayed);
        $this->assertTrue($retry->replayed);
        $this->assertSame($first->json, $retry->json, 'idéntico byte a byte');
        $this->assertSame($first->json, $third->json);
        $this->assertSame(1, DB::table('redemptions')->count());
        $this->assertSame(1, DB::table('prize_outbox')->count(), 'un solo aviso de acreditación');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'code.redeemed')->count());
        $this->assertSame(50000, (new StubBalanceProvider)->balanceFor('host-a:ana'), 'el saldo sube una sola vez');
    }

    public function test_la_misma_clave_con_otro_codigo_es_un_error_de_uso(): void
    {
        $b = $this->rf->activeBatch();
        $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000001');
        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:ana', 'clave-0000001', $b['codes'][1]));
        $this->assertSame(RedeemFailure::KEY_REUSE, $e->reason);
        $this->assertSame(422, $e->httpStatus());
        $this->assertSame(1, DB::table('redemptions')->count(), 'el segundo código no se consumió');
        $this->assertNull(DB::table('codes')->whereNotNull('redeemed_at')->where('idx', '!=', DB::table('codes')->whereNotNull('redeemed_at')->value('idx'))->first());
    }

    public function test_usuarios_distintos_pueden_usar_la_misma_clave(): void
    {
        $b = $this->rf->activeBatch();
        $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000001');
        $r = $this->redeem($b['codes'][1], 'host-a:beto', 'clave-0000001');
        $this->assertSame(2, DB::table('redemptions')->count());
        $this->assertFalse($r['alreadyPlayed']);
    }

    public function test_un_intento_fallido_no_quema_la_clave(): void
    {
        $b = $this->rf->activeBatch();
        $this->failureOf(fn () => $this->svc->redeem('host-a:ana', 'clave-0000001', RedeemFixtures::unknownCode()));
        $r = $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000001');
        $this->assertFalse($r['alreadyPlayed']);
    }

    // --- Todo o nada ---------------------------------------------------------------------------------------------------------

    public function test_si_algo_falla_despues_de_reclamar_se_deshace_todo(): void
    {
        $b = $this->rf->activeBatch();
        $boom = new Presentation(fn () => throw new \RuntimeException('falla simulada al construir el resultado'));
        $broken = $this->rf->service($boom);

        try {
            $broken->redeem('host-a:ana', 'clave-0000001', $b['codes'][0]);
            $this->fail('debía fallar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('falla simulada', $e->getMessage());
        }

        $this->assertSame(0, DB::table('codes')->whereNotNull('redeemed_at')->count(), 'el código NO quedó reclamado');
        $this->assertSame(0, DB::table('redemptions')->count());
        $this->assertSame(0, DB::table('prize_outbox')->count());
        $this->assertSame(0, DB::table('audit_log')->where('action', 'code.redeemed')->count());

        // Y el usuario puede reintentar con normalidad.
        $r = $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000001');
        $this->assertFalse($r['alreadyPlayed']);
    }

    // --- Stock y saldo ------------------------------------------------------------------------------------------------------------

    public function test_ca20_al_redimir_todos_los_codigos_se_entrega_exactamente_el_stock_definido(): void
    {
        $pool = Fixtures::pool(none: 60, p10k: 30, p50k: 9, p100k: 1);
        $b = $this->rf->activeBatch($pool);

        $delivered = [];
        foreach ($b['codes'] as $i => $code) {
            $r = $this->redeem($code, 'host-a:u'.$i, 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT));
            $id = $r['outcome']['prize']['id'];
            $delivered[$id] = ($delivered[$id] ?? 0) + 1;
        }
        ksort($delivered);

        $this->assertSame(['none' => 60, 'p100k' => 1, 'p10k' => 30, 'p50k' => 9], $delivered);
        $this->assertSame(100, DB::table('redemptions')->count());
        $this->assertSame(40, DB::table('prize_outbox')->count(), 'un aviso por cada premio que no es "sin premio"');
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_el_saldo_de_demostracion_suma_solo_los_premios_en_dinero_del_usuario(): void
    {
        config(['gamification.demo.initial_balance' => 120000]);
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
        $balances = new StubBalanceProvider;

        $this->assertSame(120000, $balances->balanceFor('host-a:ana'));
        $this->redeem($this->codeWith($b, 'p50k'), 'host-a:ana', 'clave-0000001');
        $this->redeem($this->codeWith($b, 'none'), 'host-a:ana', 'clave-0000002');
        $this->assertSame(170000, $balances->balanceFor('host-a:ana'));
        $this->assertSame(120000, $balances->balanceFor('host-a:beto'), 'el premio de otro usuario no suma');
    }

    // --- Bloqueo por intentos fallidos -----------------------------------------------------------------------------------------

    public function test_tras_varios_intentos_fallidos_el_usuario_queda_bloqueado_incluso_con_un_codigo_valido(): void
    {
        $b = $this->rf->activeBatch();
        config(['gamification.redeem.user.max_failures' => 3, 'gamification.redeem.user.lockout_base_seconds' => 60]);
        Carbon::setTestNow('2026-10-20 12:00:00');

        for ($i = 0; $i < 3; $i++) {
            $this->failureOf(fn () => $this->svc->redeem('host-a:ana', 'clave-0000010'.$i, RedeemFixtures::unknownCode("x$i")));
        }

        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:ana', 'clave-0000020', $b['codes'][0]));
        $this->assertSame(RedeemFailure::RATE_LIMITED, $e->reason);
        $this->assertSame(429, $e->httpStatus());
        $this->assertSame(60, $e->retryAfter);
        $this->assertSame(0, DB::table('redemptions')->count(), 'bloqueado: ni siquiera consulta el código');

        // Otro usuario (otra IP) no se ve afectado.
        $r = $this->svc->redeem('host-a:beto', 'clave-0000030', $b['codes'][0], '10.9.9.9');
        $this->assertFalse($r->replayed);

        // Pasado el bloqueo, ana puede volver.
        Carbon::setTestNow('2026-10-20 12:01:01');
        $e2 = $this->failureOf(fn () => $this->svc->redeem('host-a:ana', 'clave-0000040', $b['codes'][0]));
        $this->assertSame(RedeemFailure::ALREADY_USED, $e2->reason, 'ya no está bloqueada (y el código lo usó beto)');
    }

    public function test_probar_codigos_ajenos_ya_usados_tambien_cuenta_como_fallo(): void
    {
        $b = $this->rf->activeBatch();
        config(['gamification.redeem.user.max_failures' => 2]);
        foreach ([0, 1] as $i) {
            $this->redeem($b['codes'][$i], 'host-a:victima'.$i, 'clave-000000'.$i);
        }
        // Un atacante que acierta códigos que existen pero ya son de otros.
        foreach ([0, 1] as $i) {
            $this->failureOf(fn () => $this->svc->redeem('host-a:atacante', 'clave-attack0'.$i, $b['codes'][$i]));
        }
        $e = $this->failureOf(fn () => $this->svc->redeem('host-a:atacante', 'clave-attack99', $b['codes'][5]));
        $this->assertSame(RedeemFailure::RATE_LIMITED, $e->reason);
    }

    public function test_un_canje_exitoso_reinicia_los_fallos_de_ese_usuario(): void
    {
        $b = $this->rf->activeBatch();
        config(['gamification.redeem.user.max_failures' => 3]);
        for ($i = 0; $i < 2; $i++) {
            $this->failureOf(fn () => $this->svc->redeem('host-a:ana', 'clave-0000010'.$i, RedeemFixtures::unknownCode("e$i")));
        }
        $this->redeem($b['codes'][0], 'host-a:ana', 'clave-0000020'); // acierta: reinicia
        for ($i = 0; $i < 2; $i++) {
            $this->failureOf(fn () => $this->svc->redeem('host-a:ana', 'clave-0000030'.$i, RedeemFixtures::unknownCode("f$i")));
        }
        // 2 + 2 fallos pero con un acierto en medio: no llegó a 3 seguidos, sigue sin bloqueo.
        $r = $this->redeem($b['codes'][1], 'host-a:ana', 'clave-0000040');
        $this->assertFalse($r['alreadyPlayed']);
    }
}
