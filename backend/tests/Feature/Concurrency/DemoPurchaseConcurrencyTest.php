<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoShop;
use App\Domain\Demo\DemoShopException;
use App\Domain\Demo\DemoSwitch;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;

/**
 * Compras de la muestra con procesos REALES contra PostgreSQL (SPEC-006 CA-8, amenazas T-D4 y T-D5): ningún código a dos personas, ninguna
 * orden duplicada y ningún tope superado, midiendo el ESTADO FINAL de la base y no solo las respuestas.
 */
final class DemoPurchaseConcurrencyTest extends ConcurrencyTestCase
{
    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local'];
    }

    private const ITEMS = [['productId' => 'recarga-50', 'quantity' => 1]]; // 50.000 → 2 códigos, 5.000 de saldo con la configuración por defecto

    /** @param array<string, mixed> $credit */
    private function arm(array $credit = []): void
    {
        config(['gamification.demo.allowed' => true]);
        config(['gamification.hosts.standalone-dev' => ['alg' => 'HS256', 'key' => base64_encode(str_repeat('k', 32))]]);
        $c = DemoConfig::defaults();
        $c['credit'] = array_replace($c['credit'], $credit);
        $c['mix'] = [
            'roulette' => ['prizes' => [['type' => 'money', 'amount' => 5000, 'count' => 4]], 'noPrizeCount' => 16],
            'scratch' => ['prizes' => [['type' => 'money', 'amount' => 10000, 'count' => 4]], 'noPrizeCount' => 16],
        ];
        $c = DemoConfig::validate($c);
        (new DemoSwitch)->saveConfig($c, 'test');
        $this->app->make(DemoSeeder::class)->seed($c, 'test');
        (new DemoSwitch)->enable('test');
    }

    private static function user(int $i): string
    {
        return 'standalone-dev:muestra.'.str_pad(dechex($i + 1), 24, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private static function attempt(string $user, string $key): array
    {
        try {
            $r = (new DemoShop)->purchase($user, $key, self::ITEMS);

            return ['status' => 'ok', 'orderId' => $r['orderId'], 'codes' => array_column($r['codes'], 'code'), 'replayed' => $r['replayed']];
        } catch (DemoShopException $e) {
            return ['status' => $e->errorCode];
        }
    }

    public function test_cincuenta_personas_compran_a_la_vez_sobre_un_pool_menor_que_la_demanda(): void
    {
        $this->arm(); // pool de 40 códigos; cada compra pide 2: caben exactamente 20
        $results = $this->storm(50, fn (int $i): array => self::attempt(self::user($i), 'compra-'.$i.'-0001'));

        $this->assertSame(['demoPoolExhausted' => 30, 'ok' => 20], $this->tally($results, 'status'));
        $codes = array_merge(...array_map(fn (array $r) => $r['codes'], array_filter($results, fn (array $r) => $r['status'] === 'ok')));
        $this->assertCount(40, $codes);
        $this->assertCount(40, array_unique($codes), 'ningún código llegó a dos personas');

        $this->assertSame(['available' => 0, 'assigned' => 40], [
            'available' => (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n,
            'assigned' => (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned'")->n,
        ]);
        $this->assertSame(20, (int) DB::selectOne('SELECT count(*) AS n FROM demo_orders')->n, 'las compras rechazadas no dejaron orden');
        $this->assertSame(20, (int) DB::selectOne("SELECT count(*) AS n FROM demo_wallet_ledger WHERE kind = 'purchase_credit'")->n);
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool p WHERE state = 'assigned' AND NOT EXISTS (SELECT 1 FROM demo_orders o WHERE o.id = p.order_id AND o.user_id = p.assigned_to)")->n, 'cada código asignado pertenece a una orden de su dueño');
    }

    public function test_la_misma_compra_repetida_en_paralelo_se_hace_una_sola_vez(): void
    {
        $this->arm();
        $user = self::user(0);
        $results = $this->storm(20, fn (int $i): array => self::attempt($user, 'misma-clave-001'));

        $this->assertSame(['ok' => 20], $this->tally($results, 'status'));
        $this->assertCount(1, array_unique(array_column($results, 'orderId')), 'todas ven la misma orden');
        $this->assertCount(1, array_unique(array_map(fn (array $r) => implode(',', $r['codes']), $results)), 'y los mismos códigos');
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_orders')->n);
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_wallet_ledger WHERE kind = \'purchase_credit\'')->n, 'una sola carga de saldo');
        $this->assertSame(2, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned'")->n);
        $this->assertSame(19, count(array_filter($results, fn (array $r) => $r['replayed'])));
    }

    public function test_una_persona_con_claves_distintas_en_paralelo_no_supera_el_tope_de_la_sesion(): void
    {
        $this->arm(['maxCodesPerPurchase' => 2, 'maxCodesPerSession' => 4]);
        $user = self::user(0);
        $results = $this->storm(20, fn (int $i): array => self::attempt($user, 'otra-clave-'.$i.'-01'));

        $this->assertSame(['ok' => 20], $this->tally($results, 'status'));
        $this->assertSame(4, (int) DB::selectOne('SELECT COALESCE(SUM(codes_granted), 0) AS n FROM demo_orders')->n, 'exactamente el tope, ni uno más');
        $this->assertSame(4, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'assigned'")->n);
        $this->assertSame(20, (int) DB::selectOne('SELECT count(*) AS n FROM demo_orders')->n);
        $this->assertSame(20 * 5000, (int) DB::selectOne('SELECT SUM(amount) AS n FROM demo_wallet_ledger')->n, 'el saldo se cargó en las 20 compras');
    }
}
