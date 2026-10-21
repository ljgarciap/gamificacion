<?php

namespace Tests\Feature\Host;

use App\Domain\Admin\SecretBox;
use App\Domain\Audit\AuditLog;
use App\Domain\Balance\HttpBalanceProvider;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Fulfillment\HttpPrizeFulfillment;
use App\Domain\Fulfillment\OutboxDispatcher;
use App\Domain\Fulfillment\PermanentDeliveryFailure;
use App\Domain\Fulfillment\PrizeFulfillment;
use App\Domain\Hosts\HostClient;
use App\Domain\Hosts\HostService;
use App\Domain\Hosts\HostUnavailable;
use App\Domain\Hosts\HostUrlGuard;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Tests\DbTestCase;
use Tests\Support\Fixtures;
use Tests\Support\HostTokens;

/** Llamadas salientes al host: firma, SSRF, reintentos y saldo (SPEC-003 P5b, CA-35/37, amenazas T18–T24). */
final class HostCallsTest extends DbTestCase
{
    private const USER = 'superapp:u-42';

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $seen = [];

    /** @var callable(RequestInterface): Response|\Throwable */
    private $reply;

    private int $hostId;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        config(['gamification.hosts' => ['superapp' => ['alg' => 'HS256', 'key' => 'k'], 'superapp:eu' => ['alg' => 'HS256', 'key' => 'k']]]);
        $this->reply = fn () => new Response(200, [], '{}');
        $this->hostId = $this->registerHost('superapp', 'https://host.example.com/cb', 'https://host.example.com/balance');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guard(array $map = []): HostUrlGuard
    {
        return new HostUrlGuard(false, [], fn (string $h): array => $map[$h] ?? ['host.example.com' => ['8.8.8.8'], 'otro.example.com' => ['1.1.1.1']][$h] ?? []);
    }

    private function registerHost(string $issuer, string $cb, string $bal): int
    {
        $svc = new HostService(new AuditLog, new SecretBox, $this->guard());
        $r = $svc->create(['issuer' => $issuer, 'callbackUrl' => $cb, 'balanceUrl' => $bal], 'test');
        $this->secret = $r['secret'];

        return $r['id'];
    }

    private function client(?HostUrlGuard $guard = null): HostClient
    {
        $handler = function (RequestInterface $req, array $opts) {
            $this->seen[] = ['request' => $req, 'options' => $opts];
            $r = ($this->reply)($req);
            if ($r instanceof \Throwable) {
                return Create::rejectionFor($r);
            }

            return Create::promiseFor($r);
        };

        return new HostClient(new HostService(new AuditLog, new SecretBox, $this->guard()), $guard ?? $this->guard(), $handler);
    }

    private function signed(string $secret, string $ts, string $content): string
    {
        return hash_hmac('sha256', $ts.'.'.$content, $secret);
    }

    private function payload(): array
    {
        return ['redemption_id' => 'r-1', 'user_id' => self::USER, 'prize' => ['id' => 'p10k', 'type' => 'money', 'amount' => 10000]];
    }

    public function test_el_aviso_va_firmado_con_el_secreto_actual_y_conexion_fijada(): void
    {
        (new HttpPrizeFulfillment($this->client()))->deliver($this->payload());

        $req = $this->seen[0]['request'];
        $opts = $this->seen[0]['options'];
        $body = (string) $req->getBody();
        $ts = $req->getHeaderLine('X-Gamif-Timestamp');
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame((string) Carbon::now()->getTimestamp(), $ts);
        $this->assertSame('r-1', $req->getHeaderLine('X-Gamif-Event-Id'));
        $this->assertSame($this->signed($this->secret, $ts, $body), $req->getHeaderLine('X-Gamif-Signature'));
        $this->assertSame(self::USER, json_decode($body, true)['user_id']);
        // Defensas de red: sin redirecciones, tiempos, y conexión fijada a la IP VALIDADA (no a lo que resuelva después).
        $this->assertFalse($opts['allow_redirects']);
        $this->assertSame(['host.example.com:443:8.8.8.8'], $opts['curl'][CURLOPT_RESOLVE]);
        $this->assertGreaterThan(0, $opts['timeout']);
        $this->assertGreaterThan(0, $opts['connect_timeout']);
        $this->assertTrue($opts['verify']);
        $this->assertSame(['https'], $opts['protocols']);
        $this->assertArrayNotHasKey('stream', $opts, 'con `stream` Guzzle usa el manejador de flujos, que ignora la fijación de IP');
    }

    public function test_guzzle_acepta_de_verdad_las_opciones_de_curl_que_enviamos(): void
    {
        // El transporte falso de las demás pruebas NO valida opciones: una opción que Guzzle rechaza (p. ej. CURLOPT_PROTOCOLS, o `stream` junto a
        // `curl`) rompería TODAS las llamadas reales sin que ninguna prueba lo notara. Aquí se construye el manejador real de cURL (sin enviar).
        (new HttpPrizeFulfillment($this->client()))->deliver($this->payload());
        $seen = $this->seen[0];
        $handle = (new CurlFactory(1))->create($seen['request'], $seen['options']);
        $this->assertInstanceOf(EasyHandle::class, $handle);
        $this->assertNotNull($handle->handle);
    }

    public function test_tras_rotar_firma_el_secreto_nuevo(): void
    {
        $svc = new HostService(new AuditLog, new SecretBox, $this->guard());
        $new = $svc->rotate($this->hostId, 'test');
        (new HttpPrizeFulfillment($this->client()))->deliver($this->payload());
        $req = $this->seen[0]['request'];
        $this->assertSame($this->signed($new, $req->getHeaderLine('X-Gamif-Timestamp'), (string) $req->getBody()), $req->getHeaderLine('X-Gamif-Signature'));
    }

    public function test_clasificacion_de_respuestas(): void
    {
        $f = new HttpPrizeFulfillment($this->client());
        foreach ([200, 201, 202, 204] as $ok) {
            $this->reply = fn () => new Response($ok);
            $f->deliver($this->payload());
        }
        $this->addToAssertionCount(1);
        foreach ([408, 425, 429, 500, 502, 503, 504] as $retry) {
            $this->reply = fn () => new Response($retry, [], 'cuerpo secreto del host');
            try {
                $f->deliver($this->payload());
                $this->fail("$retry debía reintentarse");
            } catch (HostUnavailable $e) {
                $this->assertStringContainsString((string) $retry, $e->getMessage());
                $this->assertStringNotContainsString('cuerpo secreto', $e->getMessage(), 'T24: nunca el cuerpo del host');
            }
        }
        foreach ([301, 302, 307, 400, 401, 403, 404, 410, 422] as $perm) {
            $this->reply = fn () => new Response($perm, ['Location' => 'http://127.0.0.1/'], 'x');
            try {
                $f->deliver($this->payload());
                $this->fail("$perm debía ser permanente");
            } catch (PermanentDeliveryFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_un_fallo_de_red_se_reintenta_sin_filtrar_la_url(): void
    {
        $this->reply = fn () => new ConnectException('cURL error 6: https://host.example.com/cb?token=abc', new Request('POST', 'https://host.example.com/cb'));
        try {
            (new HttpPrizeFulfillment($this->client()))->deliver($this->payload());
            $this->fail('debía lanzar');
        } catch (HostUnavailable $e) {
            $this->assertStringNotContainsString('token=abc', $e->getMessage());
            $this->assertStringNotContainsString('host.example.com', $e->getMessage());
        }
    }

    public function test_si_la_url_guardada_ya_no_es_segura_no_se_llama(): void
    {
        // El DNS del host ahora apunta a la red interna (DNS rebinding / cambio posterior): no hay llamada.
        $client = $this->client($this->guard(['host.example.com' => ['169.254.169.254']]));
        try {
            (new HttpPrizeFulfillment($client))->deliver($this->payload());
            $this->fail('debía lanzar');
        } catch (HostUnavailable $e) {
            $this->assertStringContainsString('ya no es segura', $e->getMessage());
        }
        $this->assertSame([], $this->seen, 'no salió ninguna petición');

        // Resolución mixta (una pública y una interna): también se rechaza entera.
        $mixed = $this->client($this->guard(['host.example.com' => ['8.8.8.8', '10.0.0.1']]));
        $this->expectException(HostUnavailable::class);
        (new HttpPrizeFulfillment($mixed))->deliver($this->payload());
    }

    public function test_sin_host_activo_o_con_otro_emisor_no_se_llama(): void
    {
        $f = new HttpPrizeFulfillment($this->client());
        $p = ['user_id' => 'desconocido:u-1'] + $this->payload();
        try {
            $f->deliver($p);
            $this->fail();
        } catch (HostUnavailable) {
            $this->addToAssertionCount(1);
        }
        DB::update('UPDATE hosts SET active = false WHERE id = ?', [$this->hostId]);
        $this->expectException(HostUnavailable::class);
        $f->deliver($this->payload());
    }

    public function test_el_emisor_mas_largo_gana_y_un_prefijo_parcial_no_cuenta(): void
    {
        $svc = new HostService(new AuditLog, new SecretBox, $this->guard());
        $eu = $svc->create(['issuer' => 'superapp:eu', 'callbackUrl' => 'https://otro.example.com/cb', 'balanceUrl' => 'https://otro.example.com/b'], 't');
        (new HttpPrizeFulfillment($this->client()))->deliver(['user_id' => 'superapp:eu:u-1'] + $this->payload());
        $this->assertSame('otro.example.com', $this->seen[0]['request']->getUri()->getHost());
        $this->assertNotNull($eu['id']);

        $this->seen = [];
        $this->expectException(HostUnavailable::class);
        (new HttpPrizeFulfillment($this->client()))->deliver(['user_id' => 'superappX:u-1'] + $this->payload());
    }

    // --- Saldo -------------------------------------------------------------------------------------------------

    private function balanceReply(int|string|null $balance, ?string $secret = null, ?int $ts = null, bool $sign = true): void
    {
        $this->reply = function () use ($balance, $secret, $ts, $sign) {
            $body = json_encode(['balance' => $balance]);
            $ts ??= Carbon::now()->getTimestamp();
            $h = $sign ? ['X-Gamif-Timestamp' => (string) $ts, 'X-Gamif-Signature' => $this->signed($secret ?? $this->secret, (string) $ts, $body)] : [];

            return new Response(200, $h, $body);
        };
    }

    public function test_saldo_firmado_se_lee_y_la_peticion_firma_el_destino(): void
    {
        $this->balanceReply(125000);
        $this->assertSame(125000, (new HttpBalanceProvider($this->client()))->balanceFor(self::USER));

        $req = $this->seen[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('user=u-42', $req->getUri()->getQuery());
        $this->assertSame($this->signed($this->secret, $req->getHeaderLine('X-Gamif-Timestamp'), '/balance?user=u-42'), $req->getHeaderLine('X-Gamif-Signature'));
    }

    public function test_el_saldo_tambien_acepta_el_secreto_anterior_durante_la_rotacion(): void
    {
        $old = $this->secret;
        (new HostService(new AuditLog, new SecretBox, $this->guard()))->rotate($this->hostId, 't');
        $this->balanceReply(5, $old);
        $this->assertSame(5, (new HttpBalanceProvider($this->client()))->balanceFor(self::USER));
    }

    public function test_un_saldo_no_firmado_alterado_viejo_o_invalido_nunca_se_acepta(): void
    {
        $p = new HttpBalanceProvider($this->client());
        $cases = [
            'sin firma' => fn () => $this->balanceReply(100, sign: false),
            'secreto ajeno' => fn () => $this->balanceReply(100, 'otro-secreto-que-no-es'),
            'marca vieja' => fn () => $this->balanceReply(100, ts: Carbon::now()->getTimestamp() - 3600),
            'marca futura' => fn () => $this->balanceReply(100, ts: Carbon::now()->getTimestamp() + 3600),
            'negativo' => fn () => $this->balanceReply(-1),
            'texto' => fn () => $this->balanceReply('100'),
            'nulo' => fn () => $this->balanceReply(null),
            'enorme' => fn () => $this->balanceReply(PHP_INT_MAX),
        ];
        foreach ($cases as $name => $arm) {
            Cache::flush();
            $arm();
            try {
                $p->balanceFor(self::USER);
                $this->fail("$name debía rechazarse");
            } catch (HostUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
        // Cuerpo alterado después de firmar.
        Cache::flush();
        $this->reply = fn () => new Response(200, ['X-Gamif-Timestamp' => (string) Carbon::now()->getTimestamp(), 'X-Gamif-Signature' => $this->signed($this->secret, (string) Carbon::now()->getTimestamp(), '{"balance":1}')], '{"balance":999999}');
        $this->expectException(HostUnavailable::class);
        $p->balanceFor(self::USER);
    }

    public function test_respuesta_demasiado_grande_o_estado_de_error_es_host_no_disponible(): void
    {
        $p = new HttpBalanceProvider($this->client());
        $this->reply = fn () => new Response(200, [], str_repeat('x', HostClient::MAX_BODY + 10));
        try {
            $p->balanceFor(self::USER);
            $this->fail();
        } catch (HostUnavailable $e) {
            $this->assertStringContainsString('tope', $e->getMessage());
        }
        Cache::flush();
        $this->reply = fn () => new Response(500, [], 'boom');
        $this->expectException(HostUnavailable::class);
        $p->balanceFor(self::USER);
    }

    public function test_el_interruptor_corta_al_host_tras_fallos_seguidos_y_se_recupera(): void
    {
        DB::table('settings')->insert([['key' => 'host.breaker_failures', 'value' => '3', 'updated_by' => 't', 'updated_at' => now()], ['key' => 'host.breaker_open_seconds', 'value' => '30', 'updated_by' => 't', 'updated_at' => now()]]);
        Cache::flush();
        $p = new HttpBalanceProvider($this->client());
        $this->reply = fn () => new Response(503);
        for ($i = 0; $i < 3; $i++) {
            try {
                $p->balanceFor(self::USER);
            } catch (HostUnavailable) {
            }
        }
        $calls = count($this->seen);
        $this->assertSame(3, $calls);

        // Abierto: falla rápido SIN llamar al host.
        try {
            $p->balanceFor(self::USER);
            $this->fail();
        } catch (HostUnavailable $e) {
            $this->assertStringContainsString('cortado', $e->getMessage());
        }
        $this->assertCount($calls, $this->seen);

        // Pasado el tiempo vuelve a intentar y, si responde bien, se cierra.
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        Cache::flush(); // la caché de pruebas no avanza con el reloj simulado; el vencimiento real lo da `open_until`
        $this->balanceReply(7);
        $this->assertSame(7, $p->balanceFor(self::USER));
    }

    public function test_la_outbox_deja_failed_de_inmediato_un_rechazo_permanente_y_reintenta_lo_transitorio(): void
    {
        $this->app->instance(PrizeFulfillment::class, new HttpPrizeFulfillment($this->client()));
        $mk = function (string $user): int {
            $rid = (string) Str::uuid();
            $f = new Fixtures;
            $this->app->instance(BeaconClient::class, $f->beacon);
            $batch = $f->active();
            $code = (int) DB::table('codes')->where('batch_id', $batch)->orderBy('idx')->value('id');
            DB::update('UPDATE codes SET redeemed_at = now(), redeemed_by = ? WHERE id = ?', [$user, $code]);
            DB::insert("INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response) VALUES (?, ?, ?, ?, ?, 'p10k', '{}')", [$rid, $code, $batch, $user, Str::random(16)]);

            return (int) DB::selectOne('INSERT INTO prize_outbox (redemption_id, payload) VALUES (?, ?::jsonb) RETURNING id', [$rid, json_encode(['redemption_id' => $rid, 'user_id' => $user, 'prize' => ['id' => 'p10k', 'type' => 'money']])])->id;
        };
        $perm = $mk(self::USER);
        $this->reply = fn () => new Response(403, [], 'prohibido');
        $stats = app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(1, $stats['failed']);
        $row = DB::table('prize_outbox')->where('id', $perm)->first();
        $this->assertSame(['failed', 1], [$row->status, (int) $row->attempts]);
        $this->assertStringNotContainsString('prohibido', (string) $row->last_error);

        $trans = $mk(self::USER);
        $this->reply = fn () => new Response(503);
        $stats = app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame([1, 0], [$stats['retry'], $stats['failed']]);
        $this->assertSame('pending', DB::table('prize_outbox')->where('id', $trans)->value('status'));

        DB::update('UPDATE prize_outbox SET next_attempt_at = now() WHERE id = ?', [$trans]);
        $this->reply = fn () => new Response(200);
        $this->assertSame(1, app(OutboxDispatcher::class)->dispatchDue()['sent']);
    }

    public function test_una_respuesta_que_llega_en_trozos_se_lee_completa(): void
    {
        $body = json_encode(['balance' => 321]);
        $ts = (string) Carbon::now()->getTimestamp();
        $chunked = new class($body) implements StreamInterface
        {
            private int $pos = 0;

            public function __construct(private string $data) {}

            public function read($length): string
            {
                $out = substr($this->data, $this->pos, min(5, $length)); // nunca más de 5 bytes por lectura
                $this->pos += strlen($out);

                return $out;
            }

            public function eof(): bool
            {
                return $this->pos >= strlen($this->data);
            }

            public function __toString(): string
            {
                return $this->data;
            }

            public function close(): void {}

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return strlen($this->data);
            }

            public function tell(): int
            {
                return $this->pos;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek($offset, $whence = SEEK_SET): void {}

            public function rewind(): void {}

            public function isWritable(): bool
            {
                return false;
            }

            public function write($string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function getContents(): string
            {
                return '';
            }

            public function getMetadata($key = null)
            {
                return null;
            }
        };
        $this->reply = fn () => new Response(200, ['X-Gamif-Timestamp' => $ts, 'X-Gamif-Signature' => $this->signed($this->secret, $ts, $body)], $chunked);
        $this->assertSame(321, (new HttpBalanceProvider($this->client()))->balanceFor(self::USER));
    }

    public function test_el_endpoint_de_saldo_responde_503_host_no_disponible_sin_inventar_saldo(): void
    {
        config(['gamification.host.balance' => 'http']);
        $this->app->bind(HostClient::class, fn () => $this->client());
        $this->reply = fn () => new Response(500);
        HostTokens::configure(['superapp' => ['alg' => 'HS256', 'key' => 'k']]);
        $this->registerHost(HostTokens::ISSUER, 'https://host.example.com/cb', 'https://host.example.com/balance');
        $r = $this->getJson('/v1/balance', HostTokens::bearer('u-1'));
        $this->assertSame([503, 'hostUnavailable'], [$r->status(), $r->json('error.code')]);
        $this->assertArrayNotHasKey('amount', $r->json());
        $this->assertSame('5', $r->headers->get('Retry-After'));
    }
}
