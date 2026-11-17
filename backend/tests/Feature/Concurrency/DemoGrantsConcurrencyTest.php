<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Demo\DemoGrants;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoShop;
use App\Domain\Demo\DemoSwitch;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\PlansFixture;

/**
 * Turnos de bienvenida y reactivación con procesos REALES contra PostgreSQL (SPEC-007, T-D25): ningún ingreso simultáneo duplica un turno ni
 * una compra al mismo tiempo lo deja pasar dos veces. Se mide el ESTADO FINAL de la base.
 */
final class DemoGrantsConcurrencyTest extends ConcurrencyTestCase
{
    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local'];
    }

    /** @return array<string, mixed> */
    private function arm(): array
    {
        config(['gamification.demo.allowed' => true]);
        config(['gamification.hosts.standalone-dev' => ['alg' => 'HS256', 'key' => base64_encode(str_repeat('k', 32))]]);
        $cfg = PlansFixture::config();
        (new DemoSwitch)->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        (new DemoSwitch)->enable('test');

        return $cfg;
    }

    private function rows(string $sql, array $bindings = []): int
    {
        return (int) DB::selectOne($sql, $bindings)->n;
    }

    public function test_treinta_ingresos_a_la_vez_de_una_cuenta_nueva_dan_un_solo_turno_de_bienvenida(): void
    {
        $this->arm();
        $results = $this->storm(30, function (int $i): array {
            $s = (new DemoShop)->login('ana@example.com', '203.0.113.7');

            return ['grant' => isset($s['grant']) ? 'con' : 'sin'];
        });

        $this->assertSame(['con' => 1, 'sin' => 29], $this->tally($results, 'grant'));
        $this->assertSame(1, $this->rows("SELECT count(*) AS n FROM demo_grants WHERE kind = 'welcome'"));
        $this->assertSame(1, $this->rows("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned'"));
    }

    public function test_treinta_ingresos_a_la_vez_de_una_cuenta_inactiva_dan_una_sola_reactivacion(): void
    {
        $cfg = $this->arm();
        (new DemoShop)->login('ana@example.com', '203.0.113.7');
        $id = DB::selectOne('SELECT id FROM demo_accounts LIMIT 1')->id;
        (new DemoGrants)->simulateInactivity($id, 'test', $cfg);

        $results = $this->storm(30, function (int $i): array {
            $s = (new DemoShop)->login('ana@example.com', '203.0.113.7');

            return ['grant' => isset($s['grant']) ? $s['grant']['kind'] : 'sin'];
        });

        $this->assertSame(['reactivation' => 1, 'sin' => 29], $this->tally($results, 'grant'));
        $this->assertSame(1, $this->rows("SELECT count(*) AS n FROM demo_grants WHERE kind = 'reactivation'"));
        $this->assertSame(2, $this->rows("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned'"), 'bienvenida + reactivación');
    }

    public function test_ingresos_y_compras_a_la_vez_de_una_cuenta_inactiva_nunca_duplican_la_reactivacion(): void
    {
        $cfg = $this->arm();
        (new DemoShop)->login('ana@example.com', '203.0.113.7');
        $id = DB::selectOne('SELECT id FROM demo_accounts LIMIT 1')->id;
        $subject = DB::selectOne('SELECT subject FROM demo_accounts LIMIT 1')->subject;
        (new DemoGrants)->simulateInactivity($id, 'test', $cfg);
        $user = 'standalone-dev:'.$subject;

        $results = $this->storm(20, function (int $i) use ($user): array {
            try {
                if ($i % 2 === 0) {
                    $s = (new DemoShop)->login('ana@example.com', '203.0.113.7');

                    return ['what' => 'login', 'grant' => isset($s['grant']) ? 'con' : 'sin'];
                }
                (new DemoShop)->purchase($user, 'compra-'.$i.'-0001', [['productId' => 'b1000', 'quantity' => 1]]);

                return ['what' => 'purchase', 'grant' => 'sin'];
            } catch (\App\Domain\Demo\DemoShopException $e) {
                return ['what' => 'purchase', 'grant' => $e->errorCode];
            }
        });

        $this->assertArrayNotHasKey('crash', array_flip(array_column($results, 'crash')), json_encode($results));
        $reactivations = $this->rows("SELECT count(*) AS n FROM demo_grants WHERE kind = 'reactivation'");
        $this->assertLessThanOrEqual(1, $reactivations, 'a lo sumo una reactivación, gane quien gane la carrera');
        $this->assertSame($reactivations, $this->tally($results, 'grant')['con'] ?? 0);
        $this->assertSame(
            $reactivations + 1, // + la de bienvenida del primer ingreso
            $this->rows("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned' AND plan = 'welcome'"),
            'cada turno otorgado es exactamente un código de Bienvenida',
        );
    }
}
