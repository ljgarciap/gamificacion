<?php

namespace Tests\Feature\Contract;

use App\Domain\Redemption\RedeemFailure;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * El OpenAPI (docs/openapi.yaml) no es documentación suelta: se COMPARA con el código y con los ejemplos reales. Si alguien añade un código de
 * error, un campo o un tipo de premio sin actualizar el contrato, falla aquí.
 */
final class OpenApiContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $doc;

    private string $examples;

    protected function setUp(): void
    {
        parent::setUp();
        $base = getenv('CONTRACT_DIR') ?: '/docs';
        $file = $base.'/openapi.yaml';
        if (! is_file($file)) {
            $this->fail("No se encuentra $file (defina CONTRACT_DIR o monte ./docs).");
        }
        $this->doc = Yaml::parseFile($file);
        $this->examples = $base.'/contract/examples';
    }

    private function schema(string $name): array
    {
        return $this->doc['components']['schemas'][$name] ?? $this->fail("falta el esquema $name");
    }

    private function example(string $name): array
    {
        return json_decode((string) file_get_contents("{$this->examples}/$name.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_es_openapi_3_1_con_seguridad_bearer(): void
    {
        $this->assertStringStartsWith('3.1', $this->doc['openapi']);
        $this->assertSame('bearer', $this->doc['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame([['bearerAuth' => []]], $this->doc['security']);
    }

    public function test_los_caminos_documentados_son_exactamente_los_que_existen(): void
    {
        $this->assertSame(['/v1/balance', '/v1/codes/redeem', '/v1/dev/demo-codes', '/v1/dev/token', '/v1/spins'], $this->sorted(array_keys($this->doc['paths'])));

        // Las rutas de desarrollo están marcadas como tales y sin autenticación (es lo que emiten).
        foreach (['/v1/dev/token' => 'post', '/v1/dev/demo-codes' => 'get'] as $path => $method) {
            $this->assertTrue($this->doc['paths'][$path][$method]['x-dev-only'], $path);
            $this->assertSame([], $this->doc['paths'][$path][$method]['security'], $path);
        }

        // Las rutas reales de producción (sin las de desarrollo) coinciden con las documentadas como no-dev.
        $reales = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->map(fn ($r) => '/'.$r->uri())->filter(fn ($u) => str_starts_with($u, '/v1/') && ! str_contains($u, '/dev/'))->unique()->sort()->values()->all();
        $this->assertSame(['/v1/balance', '/v1/codes/redeem', '/v1/spins'], $reales);
    }

    public function test_los_estados_http_de_canjear_son_los_que_emite_el_servicio(): void
    {
        $documentados = array_map('intval', array_keys($this->doc['paths']['/v1/codes/redeem']['post']['responses']));
        sort($documentados);

        // Cada fallo de negocio tiene su estado en RedeemFailure; más 200 (éxito), 400 (clave), 401 (auth) y 500 (interno).
        $deNegocio = array_map(fn (string $r) => (new RedeemFailure($r))->httpStatus(), [
            RedeemFailure::INVALID, RedeemFailure::ALREADY_USED, RedeemFailure::EXPIRED, RedeemFailure::CAMPAIGN_INACTIVE,
            RedeemFailure::RATE_LIMITED, RedeemFailure::KEY_REUSE,
        ]);
        $esperados = array_values(array_unique(array_merge([200, 400, 401, 500], $deNegocio)));
        sort($esperados);
        $this->assertSame($esperados, $documentados);
    }

    public function test_los_estados_http_de_girar_son_los_que_emite_el_servicio(): void
    {
        $documentados = array_map('intval', array_keys($this->doc['paths']['/v1/spins']['post']['responses']));
        sort($documentados);
        $deNegocio = array_map(fn (string $r) => (new RedeemFailure($r))->httpStatus(), [
            RedeemFailure::SPIN_NOT_FOUND, RedeemFailure::NO_SPINS_LEFT, RedeemFailure::RESERVE_EXHAUSTED, RedeemFailure::EXPIRED,
            RedeemFailure::RATE_LIMITED, RedeemFailure::KEY_REUSE,
        ]);
        $esperados = array_values(array_unique(array_merge([200, 400, 401, 500], $deNegocio)));
        sort($esperados);
        $this->assertSame($esperados, $documentados);
    }

    public function test_los_codigos_de_error_documentados_son_exactamente_los_del_codigo(): void
    {
        $doc = $this->sorted($this->schema('ErrorCode')['enum']);
        $codigo = $this->sorted([
            RedeemFailure::INVALID, RedeemFailure::ALREADY_USED, RedeemFailure::EXPIRED, RedeemFailure::CAMPAIGN_INACTIVE,
            RedeemFailure::RATE_LIMITED, RedeemFailure::KEY_REUSE, 'idempotencyKeyRequired', 'unauthenticated', 'serverError',
            RedeemFailure::SPIN_NOT_FOUND, RedeemFailure::NO_SPINS_LEFT, RedeemFailure::RESERVE_EXHAUSTED,
        ]);
        $this->assertSame($codigo, $doc);
    }

    public function test_los_tipos_de_premio_coinciden_con_la_base_de_datos_y_con_el_cliente(): void
    {
        $this->assertSame($this->sorted(['money', 'coupon', 'points', 'extraSpin', 'none']), $this->sorted($this->schema('PrizeType')['enum']));
        // El cliente Flutter (PrizeType) tiene además `unknown` para tipos futuros; el servidor nunca lo emite.
        $migration = (string) file_get_contents(base_path('database/migrations/2026_10_20_200001_create_campaigns_and_prizes_tables.php'));
        preg_match("/type IN \\(([^)]*)\\)/", $migration, $m);
        $this->assertSame($this->sorted(['money', 'coupon', 'points', 'extraSpin', 'none']), $this->sorted(array_map(fn ($s) => trim($s, " '"), explode(',', $m[1]))));
    }

    public function test_los_campos_obligatorios_del_esquema_son_las_claves_de_una_respuesta_real(): void
    {
        $r = $this->example('redemption-roulette-win');

        $this->assertSame($this->sorted($this->schema('Redemption')['required']), $this->sorted(array_keys($r)));
        $this->assertSame($this->sorted($this->schema('Campaign')['required']), $this->sorted(array_keys($r['campaign'])));
        $this->assertSame($this->sorted($this->schema('Outcome')['required']), $this->sorted(array_keys($r['outcome'])));
        $this->assertSame($this->sorted($this->schema('Balance')['required']), $this->sorted(array_keys($this->example('balance'))));
        $this->assertSame($this->sorted($this->schema('Error')['properties']['error']['required']), $this->sorted(array_keys($this->example('error-invalid-code')['error'])));
    }

    public function test_ningun_ejemplo_trae_campos_que_el_esquema_no_conozca(): void
    {
        $prizeProps = array_keys($this->schema('Prize')['properties']);
        $campaignProps = array_keys($this->schema('Campaign')['properties']);
        foreach (['redemption-roulette-win', 'redemption-roulette-coupon', 'redemption-roulette-points', 'redemption-roulette-extra-spin', 'redemption-scratch-win', 'redemption-scratch-already-played', 'redemption-memory-win', 'redemption-memory-none', 'redemption-dice-win', 'redemption-dice-none'] as $name) {
            $r = $this->example($name);
            $this->assertSame([], array_diff(array_keys($r['campaign']), $campaignProps), "$name: campaign");
            $this->assertSame([], array_diff(array_keys($r['outcome']['prize']), $prizeProps), "$name: prize");
            $this->assertContains($r['outcome']['prize']['type'], $this->schema('PrizeType')['enum'], $name);
            $this->assertContains($r['campaign']['gameType'], $this->schema('Campaign')['properties']['gameType']['enum'], $name);
        }
    }

    public function test_los_resultados_de_cada_juego_cumplen_su_esquema(): void
    {
        $roulette = $this->example('redemption-roulette-win');
        $this->assertSame($this->sorted($this->schema('RouletteResult')['required']), $this->sorted(array_keys($roulette['outcome']['gameResult'])));
        $this->assertGreaterThanOrEqual($this->schema('RouletteConfig')['properties']['segments']['minItems'], count($roulette['campaign']['gameConfig']['segments']));
        $this->assertLessThanOrEqual($this->schema('RouletteConfig')['properties']['segments']['maxItems'], count($roulette['campaign']['gameConfig']['segments']));
        $this->assertLessThan(count($roulette['campaign']['gameConfig']['segments']), $roulette['outcome']['gameResult']['segmentIndex']);

        $scratch = $this->example('redemption-scratch-win');
        $this->assertSame($this->sorted($this->schema('ScratchResult')['required']), $this->sorted(array_keys($scratch['outcome']['gameResult'])));
        $this->assertSame($this->sorted($this->schema('ScratchConfig')['required']), $this->sorted(array_intersect(array_keys($scratch['campaign']['gameConfig']), $this->schema('ScratchConfig')['required'])));
        $this->assertCount($scratch['campaign']['gameConfig']['rows'] * $scratch['campaign']['gameConfig']['cols'], $scratch['outcome']['gameResult']['grid']);

        $memory = $this->example('redemption-memory-win');
        $this->assertSame($this->sorted($this->schema('MemoryResult')['required']), $this->sorted(array_keys($memory['outcome']['gameResult'])));
        $this->assertSame($this->sorted($this->schema('MemoryConfig')['required']), $this->sorted(array_keys($memory['campaign']['gameConfig'])));
        $this->assertCount($memory['campaign']['gameConfig']['pairs'] * 2, $memory['outcome']['gameResult']['cards']);
        $this->assertSame(2, array_count_values($memory['outcome']['gameResult']['cards'])[$memory['outcome']['prize']['id']], 'la pareja premio');

        $dice = $this->example('redemption-dice-win');
        $this->assertSame($this->sorted($this->schema('DiceResult')['required']), $this->sorted(array_keys($dice['outcome']['gameResult'])));
        $this->assertSame($this->sorted($this->schema('DiceConfig')['required']), $this->sorted(array_keys($dice['campaign']['gameConfig'])));
        $rolls = array_values(array_filter($dice['campaign']['gameConfig']['legend'], fn ($l) => $l['prize']['id'] === $dice['outcome']['prize']['id']))[0]['rolls'];
        $shown = $dice['outcome']['gameResult']['dice'];
        sort($shown);
        $this->assertContains($shown, $rolls, 'los dados mostrados son una combinación del premio');
    }

    public function test_el_patron_de_la_idempotency_key_coincide_con_el_del_controlador(): void
    {
        $doc = $this->doc['paths']['/v1/codes/redeem']['post']['parameters'][0]['schema']['pattern'];
        $ctl = (string) file_get_contents(app_path('Http/Controllers/RedeemController.php'));
        preg_match("/KEY_PATTERN = '([^']+)'/", $ctl, $m);
        $this->assertSame(trim($m[1], '/'), trim($doc, '/'));
    }

    /** @param array<int, string> $a @return list<string> */
    private function sorted(array $a): array
    {
        $a = array_values($a);
        sort($a);

        return $a;
    }
}
