<?php

namespace Tests\Feature\Contract;

use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoSwitch;
use Tests\DbTestCase;
use Tests\Support\HostTokens;

/**
 * Ejemplos del contrato de la MUESTRA (`docs/contract/examples/demo-*.json`): respuestas reales de `/v1/demo/session` y `/v1/demo/purchase`
 * (normalizadas). El cliente Flutter (`DemoApi`) las lee y las parsea en `packages/gamification_http/test/demo_contract_test.dart`: si el
 * backend cambia un campo sin actualizar el contrato falla aquí; si el cliente no lo entiende, falla allá. Misma mecánica que
 * `ContractExamplesTest`. Regenerar:  UPDATE_CONTRACT_EXAMPLES=1 php artisan test --filter=DemoContractExamplesTest
 */
final class DemoContractExamplesTest extends DbTestCase
{
    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure(['standalone-dev' => ['alg' => 'HS256', 'key' => base64_encode(HostTokens::SECRET)]]);
        config(['gamification.demo.allowed' => true]);
    }

    private function dir(): string
    {
        $dir = (getenv('CONTRACT_DIR') ?: '/docs').'/contract/examples';
        if (! is_dir($dir)) {
            getenv('UPDATE_CONTRACT_EXAMPLES') === '1' ? mkdir($dir, 0775, true) : $this->fail("No se encuentra $dir (defina CONTRACT_DIR o monte ./docs).");
        }

        return $dir;
    }

    /** Valores que cambian en cada ejecución → fijos. */
    private function normalize(mixed $v, string $key = ''): mixed
    {
        if ($v instanceof \stdClass) {
            $out = new \stdClass;
            foreach (get_object_vars($v) as $k => $x) {
                $out->$k = $this->normalize($x, (string) $k);
            }

            return $out;
        }
        if (is_array($v)) {
            return array_map(fn ($x) => $this->normalize($x, $key), $v);
        }

        return match ($key) {
            'token' => 'eyJ.demo.token',
            'userId' => 'standalone-dev:muestra.0123456789abcdef01234567',
            'subject' => 'muestra.0123456789abcdef01234567',
            'orderId' => '00000000-0000-4000-8000-000000000001',
            'code' => 'RULETA-AAAA-BBBB-CCC',
            default => $v,
        };
    }

    private function shape(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $props = get_object_vars($v);
            ksort($props);

            return $props === [] ? '(objeto vacío)' : array_map(fn ($x) => $this->shape($x), $props);
        }
        if (is_array($v)) {
            return $v === [] ? '(lista vacía)' : ['(lista)', $this->shape($v[0])];
        }

        return get_debug_type($v);
    }

    private function check(string $name, string $json): void
    {
        $file = $this->dir()."/$name.json";
        $normalized = $this->normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        if (getenv('UPDATE_CONTRACT_EXAMPLES') === '1') {
            file_put_contents($file, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertFileExists($file, "falta $name.json: regenérelo con UPDATE_CONTRACT_EXAMPLES=1");
        $committed = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals($this->shape($committed), $this->shape($normalized), "$name.json ya no coincide con lo que responde la API");
    }

    public function test_ejemplos_de_sesion_y_compra_de_la_muestra(): void
    {
        $c = DemoConfig::defaults();
        $c['user']['initialBalance'] = 5000;
        $c['mix'] = ['roulette' => ['prizes' => [['type' => 'money', 'amount' => 5000, 'count' => 2]], 'noPrizeCount' => 8]];
        $cfg = DemoConfig::validate($c);
        $switch = new DemoSwitch;
        $switch->saveConfig($cfg, 'test');
        $this->app->make(DemoSeeder::class)->seed($cfg, 'test');
        $switch->enable('test');

        $session = $this->postJson('/v1/demo/session')->assertOk();
        $this->check('demo-session', $session->getContent());

        $order = $this->postJson('/v1/demo/purchase', ['items' => [['productId' => 'recarga-20', 'quantity' => 2]]],
            ['Authorization' => 'Bearer '.$session->json('token'), 'Idempotency-Key' => 'compra-0001'])->assertOk();
        $this->check('demo-order', $order->getContent());

        $status = $this->getJson('/v1/demo/status')->assertOk();
        $this->check('demo-status', $status->getContent());
    }
}
