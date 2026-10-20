<?php

namespace Tests\Feature\Api;

use App\Domain\Redemption\RedeemService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;
use Tests\Support\HostTokens;
use Tests\Support\RedeemFixtures;

final class RedeemApiTest extends DbTestCase
{
    private RedeemFixtures $rf;

    /** @var array{batch: string, campaign: string, codes: list<string>, prizeOf: array<string, string>} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->rf = new RedeemFixtures;
        $this->b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headers(string $user = 'ana', string $key = 'clave-0000001'): array
    {
        return HostTokens::bearer($user) + ['Idempotency-Key' => $key];
    }

    private function redeem(string $code, ?array $headers = null, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/v1/codes/redeem', ['code' => $code] + $body, $headers ?? $this->headers());
    }

    private function codeWith(string $prize): string
    {
        return array_search($prize, $this->b['prizeOf'], true);
    }

    // --- Éxito -------------------------------------------------------------------------------------------------------

    public function test_canjear_devuelve_200_con_el_resultado_y_nunca_se_cachea(): void
    {
        $r = $this->redeem($this->codeWith('p50k'));

        $r->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $r->assertJsonPath('outcome.prize.id', 'p50k')
            ->assertJsonPath('outcome.prize.amount', 50000)
            ->assertJsonPath('alreadyPlayed', false)
            ->assertJsonPath('campaign.gameType', 'roulette');
    }

    public function test_el_cuerpo_de_la_respuesta_es_el_json_exacto_que_se_guardo(): void
    {
        $r = $this->redeem($this->codeWith('p10k'));
        $stored = DB::selectOne('SELECT response::text AS r FROM redemptions')->r;
        $this->assertSame($stored, $r->getContent());
        $this->assertStringContainsString('"theme":{}', $r->getContent());
    }

    public function test_acepta_el_cuerpo_como_formulario(): void
    {
        $this->post('/v1/codes/redeem', ['code' => $this->b['codes'][0]], $this->headers() + ['Accept' => 'application/json'])->assertOk();
    }

    public function test_ca26_el_reintento_devuelve_el_mismo_cuerpo_y_no_acredita_dos_veces(): void
    {
        $code = $this->codeWith('p50k');
        $first = $this->redeem($code);
        $retry = $this->redeem($code);
        $again = $this->redeem(strtolower($code));

        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame($first->getContent(), $again->getContent());
        $this->assertSame(1, DB::table('redemptions')->count());
        $this->assertSame(1, DB::table('prize_outbox')->count());
    }

    // --- Autenticación -------------------------------------------------------------------------------------------------

    public function test_sin_token_es_401(): void
    {
        $r = $this->postJson('/v1/codes/redeem', ['code' => $this->b['codes'][0]], ['Idempotency-Key' => 'clave-0000001']);
        $r->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer')->assertJsonPath('error.code', 'unauthenticated');
        $this->assertSame(0, DB::table('redemptions')->count());
    }

    public function test_el_error_de_autenticacion_no_revela_por_que_fallo(): void
    {
        $now = Carbon::now()->getTimestamp();
        $variantes = [
            'sin cabecera' => [],
            'esquema distinto' => ['Authorization' => 'Basic abc'],
            'basura' => ['Authorization' => 'Bearer no.es.un.token'],
            'firma incorrecta' => ['Authorization' => 'Bearer '.HostTokens::make('u', [], str_repeat('x', 32))],
            'vencido' => ['Authorization' => 'Bearer '.HostTokens::make('u', ['iat' => $now - 900, 'exp' => $now - 600])],
            'emisor desconocido' => ['Authorization' => 'Bearer '.HostTokens::make('u', ['iss' => 'intruso'])],
            'audiencia incorrecta' => ['Authorization' => 'Bearer '.HostTokens::make('u', ['aud' => 'otra'])],
        ];
        $bodies = [];
        foreach ($variantes as $nombre => $h) {
            $r = $this->postJson('/v1/codes/redeem', ['code' => 'x'], $h + ['Idempotency-Key' => 'clave-0000001']);
            $r->assertStatus(401);
            $bodies[$nombre] = $r->getContent();
        }
        $this->assertCount(1, array_unique($bodies), 'todas las causas dan EXACTAMENTE la misma respuesta (sin oráculo)');
    }

    public function test_el_usuario_sale_del_token_y_no_del_cuerpo(): void
    {
        $this->redeem($this->b['codes'][0], $this->headers('ana'), ['user_id' => 'host-a:otro', 'userId' => 'host-a:otro', 'sub' => 'otro']);
        $this->assertSame('host-a:ana', DB::table('codes')->whereNotNull('redeemed_by')->value('redeemed_by'));
        $this->assertSame('host-a:ana', DB::table('redemptions')->value('user_id'));
    }

    public function test_dos_tokens_son_dos_usuarios_y_el_segundo_no_puede_usar_el_codigo_del_primero(): void
    {
        $code = $this->b['codes'][0];
        $this->redeem($code, $this->headers('ana'))->assertOk();
        $this->redeem($code, $this->headers('beto', 'clave-0000001'))->assertStatus(409)->assertJsonPath('error.code', 'alreadyUsed');
    }

    // --- Idempotency-Key -------------------------------------------------------------------------------------------------

    public function test_falta_la_clave_de_idempotencia(): void
    {
        $this->postJson('/v1/codes/redeem', ['code' => $this->b['codes'][0]], HostTokens::bearer())
            ->assertStatus(400)->assertJsonPath('error.code', 'idempotencyKeyRequired');
        $this->assertSame(0, DB::table('redemptions')->count());
    }

    public function test_claves_de_idempotencia_con_formato_invalido(): void
    {
        foreach (['corta', str_repeat('k', 129), 'con espacios aquí', "con\nsalto-0001", 'ñandú-000001', '../../etc/passwd'] as $bad) {
            $this->redeem($this->b['codes'][0], HostTokens::bearer() + ['Idempotency-Key' => $bad])
                ->assertStatus(400)->assertJsonPath('error.code', 'idempotencyKeyRequired');
        }
    }

    public function test_la_misma_clave_con_otro_codigo_es_422(): void
    {
        $this->redeem($this->b['codes'][0])->assertOk();
        $this->redeem($this->b['codes'][1])->assertStatus(422)->assertJsonPath('error.code', 'idempotencyKeyReuse');
    }

    // --- Errores de negocio con su estado HTTP ------------------------------------------------------------------------------

    public function test_cada_error_tiene_su_codigo_estable_y_su_estado_http(): void
    {
        // inválido
        $this->redeem(RedeemFixtures::unknownCode(), $this->headers('u1', 'clave-1000001'))
            ->assertStatus(422)->assertJsonPath('error.code', 'invalidCode');

        // ya usado
        $this->redeem($this->b['codes'][0], $this->headers('u2', 'clave-2000001'))->assertOk();
        $this->redeem($this->b['codes'][0], $this->headers('u3', 'clave-3000001'))
            ->assertStatus(409)->assertJsonPath('error.code', 'alreadyUsed');

        // vencido (lote cerrado)
        $this->rf->f->lifecycle()->close($this->b['batch'], 'tester');
        $this->redeem($this->b['codes'][1], $this->headers('u4', 'clave-4000001'))
            ->assertStatus(410)->assertJsonPath('error.code', 'expired');

        // campaña inactiva (lote comprometido y no activo)
        $inactive = $this->rf->f->draft();
        $codes = $this->rf->generateCodes($inactive);
        $this->rf->f->lifecycle()->commit($inactive, 'tester');
        $this->redeem($codes[0], $this->headers('u5', 'clave-5000001'))
            ->assertStatus(403)->assertJsonPath('error.code', 'campaignInactive');
    }

    public function test_los_errores_tienen_la_misma_forma_y_no_se_cachean(): void
    {
        $r = $this->redeem(RedeemFixtures::unknownCode());
        $r->assertJsonStructure(['error' => ['code', 'message']]);
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('retryAfter', $r->json('error'));
    }

    public function test_un_codigo_ausente_o_de_otro_tipo_es_invalido_sin_romper_nada(): void
    {
        foreach ([[], ['code' => null], ['code' => ['a', 'b']], ['code' => 12345], ['code' => '']] as $i => $body) {
            $this->postJson('/v1/codes/redeem', $body, $this->headers('u'.$i, 'clave-000000'.$i))
                ->assertStatus(422)->assertJsonPath('error.code', 'invalidCode');
        }
    }

    public function test_un_cuerpo_json_roto_es_un_codigo_invalido(): void
    {
        $this->call('POST', '/v1/codes/redeem', [], [], [], $this->transformHeadersToServerVars($this->headers() + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), '{no es json')
            ->assertStatus(422)->assertJsonPath('error.code', 'invalidCode');
    }

    // --- Límites ------------------------------------------------------------------------------------------------------------------

    public function test_el_bloqueo_por_intentos_fallidos_responde_429_con_retry_after(): void
    {
        config(['gamification.redeem.user.max_failures' => 2, 'gamification.redeem.user.lockout_base_seconds' => 90]);
        foreach ([1, 2] as $i) {
            $this->redeem(RedeemFixtures::unknownCode("x$i"), $this->headers('ana', "clave-000000$i"))->assertStatus(422);
        }
        $r = $this->redeem($this->b['codes'][0], $this->headers('ana', 'clave-0000009'));
        $r->assertStatus(429)->assertJsonPath('error.code', 'rateLimited')->assertJsonPath('error.retryAfter', 90)->assertHeader('Retry-After', '90');
        $this->assertSame(0, DB::table('redemptions')->count(), 'bloqueada: ni siquiera se consulta el código');
    }

    public function test_el_tope_general_de_peticiones_responde_con_el_mismo_formato_de_error(): void
    {
        config(['gamification.redeem.requests_per_minute' => 3]);
        foreach ([1, 2, 3] as $i) {
            $this->redeem(RedeemFixtures::unknownCode("t$i"), $this->headers('ana', "clave-100000$i"))->assertStatus(422);
        }
        $r = $this->redeem(RedeemFixtures::unknownCode('t4'), $this->headers('ana', 'clave-1000004'));
        $r->assertStatus(429)->assertJsonPath('error.code', 'rateLimited');
        $this->assertGreaterThan(0, $r->json('error.retryAfter'));
        $this->assertNotNull($r->headers->get('Retry-After'));
    }

    public function test_el_tope_de_peticiones_es_por_usuario(): void
    {
        config(['gamification.redeem.requests_per_minute' => 2]);
        foreach ([1, 2, 3] as $i) {
            $this->redeem(RedeemFixtures::unknownCode("a$i"), $this->headers('ana', "clave-100000$i"));
        }
        $this->redeem(RedeemFixtures::unknownCode('b1'), $this->headers('beto', 'clave-2000001'))->assertStatus(422);
    }

    // --- Errores internos --------------------------------------------------------------------------------------------------------

    public function test_un_error_interno_no_filtra_detalles(): void
    {
        config(['app.debug' => false]);
        $this->app->bind(RedeemService::class, fn () => throw new \RuntimeException('SELECT * FROM secretos — contraseña=123'));

        $r = $this->redeem($this->b['codes'][0]);
        $r->assertStatus(500)->assertJsonPath('error.code', 'serverError');
        $this->assertStringNotContainsString('secretos', $r->getContent());
        $this->assertStringNotContainsString('contraseña', $r->getContent());
        $this->assertStringNotContainsString('RuntimeException', $r->getContent());
    }

    // --- Saldo ------------------------------------------------------------------------------------------------------------------

    public function test_el_saldo_exige_autenticacion(): void
    {
        $this->getJson('/v1/balance')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_el_saldo_se_reporta_en_cop_y_refleja_los_premios_en_dinero(): void
    {
        config(['gamification.demo.initial_balance' => 120000]);
        $this->getJson('/v1/balance', HostTokens::bearer('ana'))->assertOk()->assertExactJson(['amount' => 120000, 'currency' => 'COP']);

        $this->redeem($this->codeWith('p50k'), $this->headers('ana'))->assertOk();
        $r = $this->getJson('/v1/balance', HostTokens::bearer('ana'));
        $r->assertOk()->assertJsonPath('amount', 170000);
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));

        $this->getJson('/v1/balance', HostTokens::bearer('beto'))->assertJsonPath('amount', 120000);
    }

    // --- Contrato con el cliente Flutter -----------------------------------------------------------------------------------------

    public function test_la_respuesta_tiene_exactamente_los_campos_que_lee_el_cliente(): void
    {
        $body = $this->redeem($this->codeWith('p10k'))->json();

        $this->assertEqualsCanonicalizing(['redemptionId', 'alreadyPlayed', 'playedAt', 'campaign', 'outcome'], array_keys($body));
        $this->assertEqualsCanonicalizing(['id', 'name', 'gameType', 'usedCodeBehavior', 'title', 'theme', 'gameConfig'], array_keys($body['campaign']));
        $this->assertEqualsCanonicalizing(['prize', 'extraSpins', 'gameResult'], array_keys($body['outcome']));
        $this->assertIsString($body['redemptionId']);
        $this->assertIsBool($body['alreadyPlayed']);
        $this->assertIsInt($body['outcome']['extraSpins']);
        $this->assertIsArray($body['outcome']['prize']);
        $this->assertIsInt($body['outcome']['gameResult']['segmentIndex']);
        foreach ($body['campaign']['gameConfig']['segments'] as $segment) {
            $this->assertArrayHasKey('id', $segment);
            $this->assertArrayHasKey('type', $segment);
            $this->assertArrayHasKey('label', $segment);
        }
    }
}
