<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoLoginCodes;
use App\Domain\Demo\DemoShop;
use App\Domain\Demo\DemoShopException;
use App\Domain\Demo\DemoSwitch;
use App\Mail\DemoLoginCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\ConcurrencyTestCase;

/**
 * Cuentas de la muestra con procesos REALES contra PostgreSQL (SPEC-006A, amenazas T-D13, T-D14, T-D18): un código lo gana una sola persona, no se
 * saltan la espera entre envíos y no se pasan del tope de cuentas. Se mide el ESTADO FINAL de la base.
 */
final class DemoAccountsConcurrencyTest extends ConcurrencyTestCase
{
    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local', 'MAIL_MAILER' => 'array'];
    }

    /** @return array<string, mixed> */
    private function config(array $accounts = []): array
    {
        config(['gamification.demo.allowed' => true]);
        config(['gamification.hosts.standalone-dev' => ['alg' => 'HS256', 'key' => base64_encode(str_repeat('k', 32))]]);
        $c = DemoConfig::defaults();
        $c['mix'] = ['roulette' => ['prizes' => [['type' => 'money', 'amount' => 5000, 'count' => 4]], 'noPrizeCount' => 16]];
        $c['accounts'] = array_replace($c['accounts'], $accounts);
        $c = DemoConfig::validate($c);
        (new DemoSwitch)->saveConfig($c, 'test');

        return $c;
    }

    public function test_cuarenta_verificaciones_a_la_vez_del_mismo_codigo_las_gana_una_sola(): void
    {
        $cfg = $this->config();
        Mail::fake();
        (new DemoLoginCodes)->request('ana@example.com', '203.0.113.1', $cfg);
        $code = Mail::sent(DemoLoginCodeMail::class)->last()->code;

        $results = $this->storm(40, function (int $i) use ($code): array {
            return ['ok' => (new DemoLoginCodes)->verify('ana@example.com', $code) ? 'gana' : 'pierde'];
        });

        $this->assertSame(['gana' => 1, 'pierde' => 39], $this->tally($results, 'ok'));
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_login_codes WHERE consumed_at IS NOT NULL')->n);
    }

    public function test_cincuenta_pedidos_a_la_vez_del_mismo_correo_envian_uno_solo_y_dejan_un_codigo_activo(): void
    {
        $cfg = $this->config();
        $results = $this->storm(50, function (int $i) use ($cfg): array {
            try {
                (new DemoLoginCodes)->request('ana@example.com', '203.0.113.'.($i % 5), $cfg);

                return ['status' => 'ok'];
            } catch (DemoShopException $e) {
                return ['status' => $e->errorCode];
            }
        });

        $this->assertSame(['ok' => 50], $this->tally($results, 'status'), 'todos reciben la misma respuesta');
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_mail_log')->n, 'se respetó la espera: un solo envío');
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_login_codes WHERE consumed_at IS NULL')->n);
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_login_codes')->n);
    }

    public function test_el_mismo_correo_que_entra_en_paralelo_crea_una_sola_cuenta_y_una_sola_carga_inicial(): void
    {
        $this->config();
        $results = $this->storm(30, function (int $i): array {
            $s = (new DemoShop)->login('ana@example.com', '203.0.113.7');

            return ['subject' => $s['subject'], 'nuevas' => $s['isNew'] ? 'nueva' : 'existente'];
        });

        $this->assertCount(1, array_unique(array_column($results, 'subject')), 'todas entran a la misma cuenta');
        $this->assertSame(1, $this->tally($results, 'nuevas')['nueva'] ?? 0, 'solo una la creó');
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n);
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_sessions')->n, 'ninguna sesión suelta');
    }

    public function test_veinte_personas_distintas_a_la_vez_no_superan_el_tope_de_cuentas(): void
    {
        $this->config(['maxAccounts' => 5]);
        $results = $this->storm(20, function (int $i): array {
            try {
                (new DemoShop)->login("persona{$i}@example.com", '203.0.113.8');

                return ['status' => 'ok'];
            } catch (DemoShopException $e) {
                return ['status' => $e->errorCode];
            }
        });

        $this->assertSame(['accountsFull' => 15, 'ok' => 5], $this->tally($results, 'status'));
        $this->assertSame(5, (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n, 'exactamente el tope');
        $this->assertSame(5, (int) DB::selectOne('SELECT count(*) AS n FROM demo_sessions')->n, 'los intentos rechazados no dejaron sesiones sueltas');
    }
}
