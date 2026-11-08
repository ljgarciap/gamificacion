<?php

namespace Tests\Feature\Demo;

use App\Domain\Demo\DemoAccounts;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoEmail;
use App\Domain\Demo\DemoLoginCodes;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoSwitch;
use App\Mail\DemoLoginCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\DbTestCase;
use Tests\Support\HostTokens;

/**
 * Cuentas persistentes de la muestra (SPEC-006A M7; CA-11…CA-19, amenazas T-D11…T-D19): ingreso con correo y código, cuenta que perdura, límites
 * de envío, código de un solo uso, correo cifrado y sesión que muere con la cuenta.
 */
final class DemoAccountsTest extends DbTestCase
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
        Mail::fake();
    }

    /** @param array<string, mixed> $accounts */
    private function arm(array $accounts = []): void
    {
        $c = DemoConfig::defaults();
        $c['user']['initialBalance'] = 1000;
        $c['mix'] = ['roulette' => ['prizes' => [['type' => 'money', 'amount' => 5000, 'count' => 4]], 'noPrizeCount' => 16]];
        $c['accounts'] = array_replace($c['accounts'], $accounts);
        $cfg = DemoConfig::validate($c);
        $switch = new DemoSwitch;
        $switch->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $switch->enable('test');
    }

    private function lastCode(): string
    {
        return Mail::sent(DemoLoginCodeMail::class)->last()->code;
    }

    private function request(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/v1/demo/auth/request', ['email' => $email]);
    }

    /** @return array<string, mixed> */
    private function login(string $email): array
    {
        $this->request($email)->assertOk();
        $r = $this->postJson('/v1/demo/auth/verify', ['email' => $email, 'code' => $this->lastCode()]);
        $r->assertOk();

        return $r->json();
    }

    private function bearer(array $session): array
    {
        return ['Authorization' => 'Bearer '.$session['token']];
    }

    /** Hace que el último código de un correo ya no esté vigente en la base. */
    private function expireCodes(): void
    {
        DB::update("UPDATE demo_login_codes SET expires_at = now() - interval '1 second'");
    }

    // --- Normalización y validación del correo (T-D17, T-D19) -----------------------------------------------------------

    /** @return array<string, array{mixed, ?string}> */
    public static function emails(): array
    {
        return [
            'normal' => ['ana@example.com', 'ana@example.com'],
            'mayúsculas y espacios se normalizan' => ["  Ana@Example.COM \n", 'ana@example.com'],
            '+alias se conserva' => ['ana+a@example.com', 'ana+a@example.com'],
            'puntos se conservan' => ['a.na@example.com', 'a.na@example.com'],
            'sin arroba' => ['ana.example.com', null],
            'dos arrobas' => ['a@b@example.com', null],
            'sin dominio' => ['ana@', null],
            'dominio sin punto' => ['ana@localhost', null],
            'inyección de cabecera CRLF' => ["ana@example.com\r\nBcc: x@evil.com", null],
            'LF suelto' => ["ana@example.com\nBcc: x@evil.com", null],
            'LF al final de la parte local (el $ de PHP lo deja pasar)' => ["ana\n@example.com", null],
            'LF al final del dominio' => ["ana@example.com\n", 'ana@example.com'],
            'LF dentro del dominio' => ["ana@exam\nple.com", null],
            'tabulador' => ["ana\t@example.com", null],
            'varios destinatarios con coma' => ['a@example.com,b@example.com', null],
            'punto y coma' => ['a@example.com;b@example.com', null],
            'con comillas' => ['"a b"@example.com', null],
            'no ASCII' => ['aña@example.com', null],
            'parte local con punto al inicio' => ['.ana@example.com', null],
            'puntos seguidos' => ['a..na@example.com', null],
            'demasiado largo' => [str_repeat('a', 250).'@example.com', null],
            'no es texto' => [['a@example.com'], null],
            'vacío' => ['', null],
        ];
    }

    /** @dataProvider emails */
    #[\PHPUnit\Framework\Attributes\DataProvider('emails')]
    public function test_el_correo_se_valida_y_normaliza(mixed $raw, ?string $esperado): void
    {
        $this->assertSame($esperado, DemoEmail::normalize($raw));
    }

    public function test_la_mascara_solo_deja_la_primera_letra_y_el_dominio(): void
    {
        $this->assertSame('a***@example.com', DemoEmail::mask('ana.perez@example.com'));
    }

    public function test_un_correo_invalido_se_rechaza_sin_enviar_nada(): void
    {
        $this->arm();
        foreach (["a@example.com\r\nBcc: x@evil.com", 'sin-arroba', ''] as $bad) {
            $this->request($bad)->assertStatus(422)->assertJsonPath('error.code', 'invalidEmail');
        }
        Mail::assertNothingSent();
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_login_codes')->n);
    }

    // --- Enumeración (T-D11, CA-11) -------------------------------------------------------------------------------------

    public function test_pedir_un_codigo_responde_igual_para_un_correo_registrado_y_uno_nuevo(): void
    {
        $this->arm();
        $this->login('ana@example.com');
        // Se vence la espera entre envíos para comparar en igualdad de condiciones.
        DB::delete('DELETE FROM demo_mail_log');

        $registrado = $this->request('ana@example.com');
        $nuevo = $this->request('nuevo@example.com');

        $this->assertSame($registrado->status(), $nuevo->status());
        $this->assertSame($registrado->getContent(), $nuevo->getContent(), 'mismo cuerpo, byte a byte');
        $this->assertSame($registrado->headers->get('Cache-Control'), $nuevo->headers->get('Cache-Control'));
        Mail::assertSent(DemoLoginCodeMail::class, fn ($m) => $m->hasTo('ana@example.com'));
        Mail::assertSent(DemoLoginCodeMail::class, fn ($m) => $m->hasTo('nuevo@example.com'));
    }

    public function test_pedir_un_codigo_no_consulta_si_la_cuenta_existe(): void
    {
        $this->arm();
        $this->login('ana@example.com');
        DB::delete('DELETE FROM demo_mail_log');
        DB::enableQueryLog();
        $this->request('ana@example.com')->assertOk();
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter($consultas, fn (string $q) => str_contains($q, 'demo_accounts'))), 'sin tocar demo_accounts no hay forma de distinguir');
    }

    public function test_todas_las_causas_de_rechazo_al_verificar_son_la_misma_respuesta(): void
    {
        $this->arm();
        $this->request('ana@example.com')->assertOk();
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '000001' : '000000';

        $respuestas = [
            'código errado' => $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $wrong]),
            'correo sin pedido' => $this->postJson('/v1/demo/auth/verify', ['email' => 'otro@example.com', 'code' => $code]),
            'formato de código' => $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => 'abc']),
            'correo inválido' => $this->postJson('/v1/demo/auth/verify', ['email' => 'no-es-correo', 'code' => $code]),
            'sin cuerpo' => $this->postJson('/v1/demo/auth/verify', []),
        ];
        $this->expireCodes();
        $respuestas['vencido'] = $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $code]);

        $primero = null;
        foreach ($respuestas as $causa => $r) {
            $this->assertSame(401, $r->status(), $causa);
            $this->assertSame('invalidCode', $r->json('error.code'), $causa);
            $primero ??= $r->getContent();
            $this->assertSame($primero, $r->getContent(), "$causa: mismo cuerpo");
        }
    }

    // --- El código: un solo uso, vence, se quema (T-D12, T-D14, CA-12) ---------------------------------------------------

    public function test_el_codigo_es_de_seis_digitos_y_se_guarda_solo_su_huella(): void
    {
        $this->arm();
        $this->request('ana@example.com')->assertOk();
        $code = $this->lastCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $row = DB::selectOne("SELECT encode(code_hash, 'hex') AS h FROM demo_login_codes");
        $this->assertSame(64, strlen($row->h));
        $this->assertStringNotContainsString($code, json_encode(DB::select('SELECT * FROM demo_login_codes')));
        $this->assertNotSame(hash('sha256', $code), $row->h, 'no es un hash sin clave (se podría invertir probando el millón de códigos)');
    }

    public function test_un_codigo_sirve_una_sola_vez(): void
    {
        $this->arm();
        $this->request('ana@example.com')->assertOk();
        $code = $this->lastCode();
        $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $code])->assertOk();
        $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $code])->assertStatus(401);
    }

    public function test_un_codigo_vencido_no_sirve(): void
    {
        $this->arm();
        $this->request('ana@example.com')->assertOk();
        $code = $this->lastCode();
        $this->expireCodes();
        $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $code])->assertStatus(401);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n, 'no se creó la cuenta');
    }

    public function test_la_vigencia_sale_de_la_configuracion_del_panel(): void
    {
        $this->arm(['codeTtlMinutes' => 7]);
        $this->request('ana@example.com')->assertOk();
        $minutos = (float) DB::selectOne('SELECT extract(epoch FROM (expires_at - created_at)) / 60 AS m FROM demo_login_codes')->m;
        $this->assertEqualsWithDelta(7.0, $minutos, 0.05);
        Mail::assertSent(DemoLoginCodeMail::class, fn ($m) => $m->minutes === 7);
    }

    public function test_cinco_intentos_fallidos_queman_el_codigo_aunque_despues_se_acierte(): void
    {
        $this->arm();
        $this->request('ana@example.com')->assertOk();
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '000001' : '000000';
        for ($i = 0; $i < DemoLoginCodes::MAX_ATTEMPTS; $i++) {
            $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $wrong])->assertStatus(401);
        }
        $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $code])->assertStatus(401);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n);
    }

    public function test_cuatro_fallos_y_luego_el_codigo_correcto_si_entra(): void
    {
        $this->arm();
        $this->request('ana@example.com')->assertOk();
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '000001' : '000000';
        for ($i = 0; $i < DemoLoginCodes::MAX_ATTEMPTS - 1; $i++) {
            $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $wrong])->assertStatus(401);
        }
        $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $code])->assertOk();
    }

    public function test_pedir_otro_codigo_anula_el_anterior(): void
    {
        $this->arm();
        $this->request('ana@example.com')->assertOk();
        $primero = $this->lastCode();
        DB::delete('DELETE FROM demo_mail_log'); // sin la espera de 60 s
        $this->request('ana@example.com')->assertOk();
        $segundo = $this->lastCode();
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_login_codes WHERE consumed_at IS NULL')->n, 'un solo código activo por correo');
        if ($primero !== $segundo) {
            $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $primero])->assertStatus(401);
        }
        $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => $segundo])->assertOk();
    }

    // --- Límites de envío (T-D13, CA-11) --------------------------------------------------------------------------------

    public function test_hay_una_espera_entre_dos_envios_al_mismo_correo_y_la_respuesta_conserva_su_forma(): void
    {
        $this->arm();
        $a = $this->request('ana@example.com');
        $b = $this->request('ana@example.com');
        $a->assertOk();
        $b->assertOk();
        $this->assertEqualsCanonicalizing(['sent', 'retryAfter'], array_keys($b->json()), 'misma forma que cuando sí se envía');
        $this->assertTrue($b->json('sent'));
        $this->assertGreaterThan(0, $b->json('retryAfter'));
        $this->assertLessThanOrEqual(DemoLoginCodes::COOLDOWN_SECONDS, $b->json('retryAfter'));
        Mail::assertSent(DemoLoginCodeMail::class, 1);
    }

    public function test_tope_de_envios_por_hora_por_correo(): void
    {
        $this->arm();
        $hash = DemoAccounts::emailHash('ana@example.com');
        for ($i = 0; $i < DemoLoginCodes::PER_EMAIL_PER_HOUR; $i++) {
            DB::insert("INSERT INTO demo_mail_log (email_hash, ip, created_at) VALUES (decode(?, 'hex'), '203.0.113.9', now() - make_interval(mins => ?))", [bin2hex($hash), 10 + $i]);
        }
        $r = $this->request('ana@example.com');
        $r->assertOk();
        $this->assertGreaterThan(0, $r->json('retryAfter'));
        Mail::assertNothingSent();
    }

    public function test_tope_de_envios_por_hora_por_ip(): void
    {
        $this->arm();
        for ($i = 0; $i < DemoLoginCodes::PER_IP_PER_HOUR; $i++) {
            DB::insert("INSERT INTO demo_mail_log (email_hash, ip, created_at) VALUES (decode(?, 'hex'), '127.0.0.1', now() - make_interval(mins => ?))", [bin2hex(random_bytes(32)), 5 + $i]);
        }
        $this->request('quien@example.com')->assertOk();
        Mail::assertNothingSent();
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_login_codes')->n, 'ni siquiera se generó un código');
    }

    public function test_tope_global_de_envios_por_hora(): void
    {
        $this->arm();
        DB::insert("INSERT INTO demo_mail_log (email_hash, ip, created_at) SELECT decode(md5(g::text) || md5(g::text), 'hex'), '10.' || (g % 250) || '.0.1', now() - interval '5 minutes' FROM generate_series(1, ?) g", [DemoLoginCodes::GLOBAL_PER_HOUR]);
        $this->request('ana@example.com')->assertOk();
        Mail::assertNothingSent();
    }

    public function test_pasada_la_hora_se_puede_volver_a_pedir(): void
    {
        $this->arm();
        $hash = DemoAccounts::emailHash('ana@example.com');
        for ($i = 0; $i < DemoLoginCodes::PER_EMAIL_PER_HOUR; $i++) {
            DB::insert("INSERT INTO demo_mail_log (email_hash, ip, created_at) VALUES (decode(?, 'hex'), '203.0.113.9', now() - make_interval(mins => ?))", [bin2hex($hash), 61 + $i]);
        }
        $this->request('ana@example.com')->assertOk();
        Mail::assertSent(DemoLoginCodeMail::class, 1);
    }

    public function test_si_el_correo_no_sale_se_dice_y_no_se_gasta_el_cupo_ni_queda_un_codigo(): void
    {
        $this->arm();
        Mail::swap(new class
        {
            public function to($u): object
            {
                return new class
                {
                    public function send($m): void
                    {
                        throw new \RuntimeException('smtp caído: contraseña=secreta ana@example.com');
                    }
                };
            }
        });
        $this->request('ana@example.com')->assertStatus(503)->assertJsonPath('error.code', 'mailUnavailable');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_login_codes')->n);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_mail_log')->n);
    }

    // --- Cuenta persistente (CA-13, CA-14, CA-15) -----------------------------------------------------------------------

    public function test_registrarse_crea_la_cuenta_con_el_saldo_inicial_y_volver_a_entrar_es_la_misma_cuenta(): void
    {
        $this->arm();
        $a = $this->login('ana@example.com');
        $this->assertTrue($a['isNew']);
        $this->assertFalse($a['resumed']);
        $this->assertSame(1000, $a['balance']);
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n);

        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-50', 'quantity' => 1]]], $this->bearer($a) + ['Idempotency-Key' => 'compra-0001'])->assertOk();

        // Otra vez, "desde otro navegador": el mismo correo entra a la MISMA cuenta, con su saldo acumulado.
        DB::delete('DELETE FROM demo_mail_log');
        $b = $this->login('ANA@example.com ');
        $this->assertFalse($b['isNew']);
        $this->assertTrue($b['resumed']);
        $this->assertSame($a['subject'], $b['subject']);
        $this->assertSame($a['userId'], $b['userId']);
        $this->assertSame(1000 + 5000, $b['balance']);
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n, 'no se duplicó la cuenta');
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM demo_wallet_ledger WHERE kind = 'initial'")->n, 'el saldo inicial se cargó una sola vez');
    }

    public function test_cada_correo_es_una_cuenta_distinta_con_su_saldo(): void
    {
        $this->arm();
        $a = $this->login('ana@example.com');
        DB::delete('DELETE FROM demo_mail_log');
        $b = $this->login('beto@example.com');
        $this->assertNotSame($a['subject'], $b['subject']);
        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-50', 'quantity' => 1]]], $this->bearer($a) + ['Idempotency-Key' => 'compra-0001'])->assertOk();
        $this->assertSame(1000, $this->getJson('/v1/balance', $this->bearer($b))->json('amount'), 'la compra de una cuenta no toca a la otra');
        $this->assertSame(6000, $this->getJson('/v1/balance', $this->bearer($a))->json('amount'));
    }

    public function test_la_cuenta_muestra_saldo_y_codigos_con_su_estado_de_juego(): void
    {
        $this->arm();
        $s = $this->login('ana@example.com');
        $this->getJson('/v1/demo/account', $this->bearer($s))->assertOk()->assertExactJson(['balance' => 1000, 'codes' => []]);

        $order = $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-50', 'quantity' => 1]]], $this->bearer($s) + ['Idempotency-Key' => 'compra-0001'])->assertOk()->json();
        $comprados = array_column($order['codes'], 'code');
        $this->assertCount(2, $comprados);

        $cuenta = $this->getJson('/v1/demo/account', $this->bearer($s))->assertOk()->assertHeader('Cache-Control')->json();
        $this->assertSame(6000, $cuenta['balance']);
        $this->assertEqualsCanonicalizing($comprados, array_column($cuenta['codes'], 'code'));
        $this->assertSame([false, false], array_column($cuenta['codes'], 'played'));

        // Se juega uno: queda marcado como jugado, el otro sigue pendiente.
        $this->postJson('/v1/codes/redeem', ['code' => $comprados[0]], $this->bearer($s) + ['Idempotency-Key' => 'canje-0001'])->assertOk();
        $despues = collect($this->getJson('/v1/demo/account', $this->bearer($s))->json('codes'))->keyBy('code');
        $this->assertTrue($despues[$comprados[0]]['played']);
        $this->assertFalse($despues[$comprados[1]]['played']);

        // Y al volver a ingresar (otro dispositivo) se ve lo mismo.
        DB::delete('DELETE FROM demo_mail_log');
        $otra = $this->login('ana@example.com');
        $this->assertSame($cuenta['balance'] + $this->balanceDelta($comprados[0], $s), $otra['balance']);
        $mismo = collect($this->getJson('/v1/demo/account', $this->bearer($otra))->json('codes'))->keyBy('code');
        $this->assertTrue($mismo[$comprados[0]]['played']);
        $this->assertFalse($mismo[$comprados[1]]['played']);
    }

    /** Lo que sumó el premio del canje (el saldo ya incluye el premio ganado). */
    private function balanceDelta(string $code, array $s): int
    {
        return $this->getJson('/v1/balance', $this->bearer($s))->json('amount') - 6000;
    }

    public function test_los_codigos_de_una_cuenta_nunca_aparecen_en_la_de_otra(): void
    {
        $this->arm();
        $a = $this->login('ana@example.com');
        DB::delete('DELETE FROM demo_mail_log');
        $b = $this->login('beto@example.com');
        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-50', 'quantity' => 1]]], $this->bearer($a) + ['Idempotency-Key' => 'compra-0001'])->assertOk();
        $this->assertSame([], $this->getJson('/v1/demo/account', $this->bearer($b))->json('codes'));
    }

    public function test_los_codigos_retirados_por_un_reinicio_ya_no_figuran_pero_el_saldo_si(): void
    {
        $this->arm();
        $s = $this->login('ana@example.com');
        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-50', 'quantity' => 1]]], $this->bearer($s) + ['Idempotency-Key' => 'compra-0001'])->assertOk();
        $this->app->make(DemoSeeder::class)->retire('test');
        $cuenta = $this->getJson('/v1/demo/account', $this->bearer($s))->assertOk()->json();
        $this->assertSame([], $cuenta['codes']);
        $this->assertSame(6000, $cuenta['balance'], 'conservar las cuentas conserva el saldo');
    }

    public function test_sin_cuenta_no_hay_sesion_ni_compra_ni_cuenta(): void
    {
        $this->arm();
        $this->postJson('/v1/demo/session')->assertStatus(401);
        $this->postJson('/v1/demo/session', ['resume' => 'muestra.'.str_repeat('a', 24)])->assertStatus(401);
        // Un token del emisor abierto con un sujeto con el formato de la muestra pero SIN cuenta tampoco compra.
        $sinCuenta = ['Authorization' => 'Bearer '.\Firebase\JWT\JWT::encode(
            ['iss' => self::ISSUER, 'sub' => 'muestra.'.str_repeat('b', 24), 'aud' => HostTokens::AUDIENCE, 'iat' => time(), 'exp' => time() + 300], HostTokens::SECRET, 'HS256',
        )];
        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-20', 'quantity' => 1]]], $sinCuenta + ['Idempotency-Key' => 'compra-0001'])->assertStatus(403)->assertJsonPath('error.code', 'demoSessionInvalid');
        $this->getJson('/v1/demo/account', $sinCuenta)->assertStatus(403);
    }

    public function test_borrada_la_cuenta_el_token_vigente_y_la_renovacion_dejan_de_servir(): void
    {
        $this->arm();
        $s = $this->login('ana@example.com');
        $this->getJson('/v1/demo/account', $this->bearer($s))->assertOk();
        $this->app->make(DemoAccounts::class)->deleteAll('test');

        $this->getJson('/v1/demo/account', $this->bearer($s))->assertStatus(403);
        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-20', 'quantity' => 1]]], $this->bearer($s) + ['Idempotency-Key' => 'compra-0002'])->assertStatus(403);
        $this->postJson('/v1/demo/session', ['resume' => $s['subject']])->assertStatus(401);

        // El mismo correo vuelve a registrarse como cuenta NUEVA: otro sujeto, sin el saldo anterior.
        DB::delete('DELETE FROM demo_mail_log');
        $nueva = $this->login('ana@example.com');
        $this->assertTrue($nueva['isNew']);
        $this->assertNotSame($s['subject'], $nueva['subject']);
    }

    public function test_el_tope_de_cuentas_se_respeta_y_las_existentes_siguen_entrando(): void
    {
        $this->arm(['maxAccounts' => 1]);
        $this->login('ana@example.com');
        DB::delete('DELETE FROM demo_mail_log');
        $this->request('beto@example.com')->assertOk();
        $this->postJson('/v1/demo/auth/verify', ['email' => 'beto@example.com', 'code' => $this->lastCode()])->assertStatus(503)->assertJsonPath('error.code', 'accountsFull');
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n);
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM demo_sessions WHERE subject NOT IN (SELECT subject FROM demo_accounts)")->n, 'el intento rechazado no dejó una sesión suelta');

        DB::delete('DELETE FROM demo_mail_log');
        $this->assertFalse($this->login('ana@example.com')['isNew'], 'la cuenta que ya existía entra aunque el tope esté lleno');
    }

    public function test_con_la_muestra_apagada_todo_es_404(): void
    {
        $this->arm();
        (new DemoSwitch)->disable('test');
        $this->request('ana@example.com')->assertStatus(404);
        $this->postJson('/v1/demo/auth/verify', ['email' => 'ana@example.com', 'code' => '123456'])->assertStatus(404);
        $this->postJson('/v1/demo/session', ['resume' => 'muestra.'.str_repeat('a', 24)])->assertStatus(404);
        $this->getJson('/v1/demo/account', HostTokens::bearer())->assertStatus(404);
    }

    // --- Datos personales (T-D16, CA-18) --------------------------------------------------------------------------------

    public function test_el_correo_esta_cifrado_en_la_base_y_no_aparece_en_ninguna_tabla_ni_en_la_bitacora(): void
    {
        $this->arm();
        $this->login('ana.privada@example.com');
        $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-50', 'quantity' => 1]]], $this->bearer($this->login2()) + ['Idempotency-Key' => 'compra-0001']);

        foreach (['demo_accounts', 'demo_login_codes', 'demo_mail_log', 'demo_sessions', 'demo_orders', 'demo_wallet_ledger', 'audit_log'] as $tabla) {
            $volcado = strtolower(json_encode(array_map(fn ($r) => (array) $r, DB::select("SELECT * FROM $tabla")), JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            $this->assertStringNotContainsString('ana.privada', $volcado, "$tabla no guarda el correo en claro");
            $this->assertStringNotContainsString('ana.privada', bin2hex('x').$volcado);
        }
        $enc = DB::selectOne("SELECT encode(email_enc, 'hex') AS e FROM demo_accounts")->e;
        $this->assertStringNotContainsString(bin2hex('ana.privada@example.com'), $enc);
    }

    /** Segunda cuenta para generar actividad sin pisar la primera. @return array<string, mixed> */
    private function login2(): array
    {
        DB::delete('DELETE FROM demo_mail_log');

        return $this->login('otra.persona@example.com');
    }

    public function test_el_correo_cifrado_esta_ligado_a_su_fila(): void
    {
        $this->arm();
        $this->login('ana@example.com');
        DB::delete('DELETE FROM demo_mail_log');
        $this->login('beto@example.com');
        $ids = array_column(DB::select('SELECT id FROM demo_accounts ORDER BY created_at, id'), 'id');
        $a = DB::selectOne("SELECT encode(email_enc, 'hex') AS e FROM demo_accounts WHERE id = ?", [$ids[0]])->e;
        // Copiar el texto cifrado de una cuenta a otra hace que no se pueda leer en el listado (el cifrado lleva el id de la fila).
        DB::update("UPDATE demo_accounts SET email_enc = decode(?, 'hex') WHERE id = ?", [$a, $ids[1]]);
        $this->expectException(\RuntimeException::class);
        $this->app->make(DemoAccounts::class)->list(1);
    }

    public function test_la_huella_del_correo_depende_del_pepper_secreto(): void
    {
        $h1 = DemoAccounts::emailHash('ana@example.com');
        $this->assertSame($h1, DemoAccounts::emailHash('ana@example.com'));
        $this->assertNotSame(hash('sha256', 'ana@example.com', true), $h1);
        $this->assertNotSame($h1, DemoAccounts::emailHash('beto@example.com'));
        config(['gamification.code_pepper' => base64_encode(random_bytes(32))]);
        $this->assertNotSame($h1, DemoAccounts::emailHash('ana@example.com'), 'con otro pepper la huella cambia');
    }

    // --- Configuración (D28) --------------------------------------------------------------------------------------------

    public function test_la_configuracion_de_cuentas_tiene_techos_duros_y_valores_por_defecto(): void
    {
        $base = DemoConfig::defaults();
        $this->assertSame(['retentionDays' => 30, 'codeTtlMinutes' => 10, 'maxAccounts' => 500], DemoConfig::validate($base)['accounts']);

        // Una configuración guardada antes de esta spec no trae `accounts`: se completa.
        unset($base['accounts']);
        $this->assertSame(DemoConfig::defaultAccounts(), DemoConfig::validate($base)['accounts']);
        $this->assertSame(DemoConfig::defaultAccounts(), DemoConfig::withDefaults($base)['accounts']);

        foreach ([
            ['retentionDays' => 0], ['retentionDays' => DemoConfig::MAX_RETENTION_DAYS + 1], ['codeTtlMinutes' => 4], ['codeTtlMinutes' => 31],
            ['maxAccounts' => 0], ['maxAccounts' => DemoConfig::MAX_ACCOUNTS + 1], ['retentionDays' => '30'],
        ] as $bad) {
            $c = DemoConfig::defaults();
            $c['accounts'] = array_replace($c['accounts'], $bad);
            try {
                DemoConfig::validate($c);
                $this->fail('debía rechazarse: '.json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('accounts.', $e->getMessage());
            }
        }
        $c = DemoConfig::defaults();
        $c['accounts']['extra'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        DemoConfig::validate($c);
    }
}
