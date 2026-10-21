<?php

namespace Tests\Feature\Contract;

use App\Domain\Redemption\RedeemFailure;
use App\Domain\Redemption\RedeemService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\DbTestCase;
use Tests\Support\Fixtures;
use Tests\Support\HostTokens;
use Tests\Support\RedeemFixtures;

/**
 * Los ejemplos de docs/contract/examples/*.json son RESPUESTAS REALES de la API (normalizadas). Esta prueba las regenera y comprueba que su FORMA
 * (claves y tipos) coincide con lo commiteado; el cliente Flutter lee esos mismos archivos y los parsea con su modelo
 * (packages/gamification_http/test/contract_test.dart). Si el backend cambia un campo sin actualizar el contrato, falla aquí; si el cliente
 * no entiende un campo, falla allá.
 *
 * Para regenerar los ejemplos tras un cambio intencional:  UPDATE_CONTRACT_EXAMPLES=1 php artisan test --filter=ContractExamplesTest
 */
final class ContractExamplesTest extends DbTestCase
{
    private const FIXED_ID = '00000000-0000-4000-8000-000000000001';

    private const FIXED_TIME = '2026-10-20T12:00:00.000000Z';

    private RedeemFixtures $rf;

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure();
        $this->rf = new RedeemFixtures;
    }

    private function dir(): string
    {
        $base = getenv('CONTRACT_DIR') ?: '/docs';
        $dir = $base.'/contract/examples';
        if (! is_dir($dir)) {
            if (getenv('UPDATE_CONTRACT_EXAMPLES') === '1') {
                mkdir($dir, 0775, true);
            } else {
                $this->fail("No se encuentra el directorio de ejemplos del contrato: $dir (defina CONTRACT_DIR o monte ./docs).");
            }
        }

        return $dir;
    }

    private function headers(string $user = 'ana', string $key = 'clave-0000001'): array
    {
        return HostTokens::bearer($user) + ['Idempotency-Key' => $key];
    }

    /**
     * Sustituye lo que cambia en cada ejecución (ids, fechas) por valores fijos. Trabaja con OBJETOS (json_decode sin `assoc`): con arreglos
     * asociativos `{}` y `[]` son indistinguibles y el ejemplo perdería justo la diferencia que haría fallar al cliente Flutter
     * (`"theme": {}` se lee como mapa; `"theme": []` como lista).
     */
    private function normalize(mixed $v, string $path = ''): mixed
    {
        if ($v instanceof \stdClass) {
            $out = new \stdClass;
            foreach (get_object_vars($v) as $k => $x) {
                $out->$k = match (true) {
                    $path === '' && $k === 'redemptionId' => self::FIXED_ID,
                    $path === '' && $k === 'playedAt' => self::FIXED_TIME,
                    $path === 'campaign' && $k === 'id' => self::FIXED_ID,
                    default => $this->normalize($x, $path === '' ? (string) $k : $path.'.'.$k),
                };
            }

            return $out;
        }
        if (is_array($v)) {
            return array_map(fn ($x) => $this->normalize($x, $path), $v);
        }

        return $v;
    }

    /** Forma (claves y tipos) de un valor, ignorando valores concretos y la longitud de las listas. Distingue `{}` de `[]`. */
    private function shape(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $props = get_object_vars($v);
            if ($props === []) {
                return '(objeto vacío)';
            }
            ksort($props);

            return array_map(fn ($x) => $this->shape($x), $props);
        }
        if (is_array($v)) {
            return $v === [] ? '(lista vacía)' : ['(lista)', $this->shape($v[0])];
        }

        return get_debug_type($v);
    }

    private function check(string $name, TestResponse $response): void
    {
        $file = $this->dir()."/$name.json";
        $normalized = $this->normalize(json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR));

        if (getenv('UPDATE_CONTRACT_EXAMPLES') === '1') {
            file_put_contents($file, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertFileExists($file, "falta el ejemplo $name.json: regenérelo con UPDATE_CONTRACT_EXAMPLES=1");
        $committed = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals($this->shape($committed), $this->shape($normalized), "el ejemplo $name.json ya no coincide con lo que responde la API");
    }

    private function redeem(string $code, ?array $headers = null): TestResponse
    {
        return $this->postJson('/v1/codes/redeem', ['code' => $code], $headers ?? $this->headers());
    }

    // --- Éxitos ---------------------------------------------------------------------------------------------------------

    public function test_ejemplos_de_redenciones_de_la_ruleta(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
        $this->check('redemption-roulette-win', $this->redeem(array_search('p50k', $b['prizeOf'], true))->assertOk());
        $this->check('redemption-roulette-none', $this->redeem(array_search('none', $b['prizeOf'], true), $this->headers('beto', 'clave-0000002'))->assertOk());
    }

    public function test_ejemplos_con_cupon_puntos_y_giro_extra(): void
    {
        $campaign = $this->rf->f->campaign('roulette');
        DB::table('prizes')->insert([
            ['campaign_id' => $campaign, 'prize_id' => 'cupon', 'type' => 'coupon', 'label' => 'Cupón 20%', 'amount' => null, 'meta' => json_encode(['coupon_code' => 'GIRA20', 'icon_key' => 'gift'])],
            ['campaign_id' => $campaign, 'prize_id' => 'pts', 'type' => 'points', 'label' => '250 puntos', 'amount' => 250, 'meta' => '{}'],
            ['campaign_id' => $campaign, 'prize_id' => 'extra', 'type' => 'money', 'label' => 'Gira de nuevo', 'amount' => 1, 'meta' => '{}'],
        ]);
        DB::table('campaigns')->where('id', $campaign)->update(['game_config' => json_encode(['segments' => ['cupon', 'pts', 'extra', 'none', 'p10k', 'none', 'p50k', 'none'], 'durationMs' => 4600, 'fullTurns' => 5])]);
        $batch = $this->rf->f->lifecycle()->createDraft($campaign, 'L', [
            ['prize_id' => 'cupon', 'quantity' => 1], ['prize_id' => 'pts', 'quantity' => 1], ['prize_id' => 'extra', 'quantity' => 1],
            ['prize_id' => 'none', 'quantity' => 1], ['prize_id' => 'p10k', 'quantity' => 1], ['prize_id' => 'p50k', 'quantity' => 1],
        ], 'tester');
        $codes = $this->rf->generateCodes($batch);
        $this->rf->f->lifecycle()->commit($batch, 'tester');
        $this->rf->f->activate($batch);
        // El ejemplo de una redención que concede giros (`extraSpins: 1`): hoy un pool con giro extra exige reserva y su código puede caer en la reserva
        // (sin código que canjear), así que el lote se «envejece» como los comprometidos antes de ese control.
        $this->rf->turnIntoExtraSpin($campaign, 'extra');
        $byPrize = array_flip($this->rf->prizeMap($batch, $codes));

        $this->check('redemption-roulette-coupon', $this->redeem($byPrize['cupon'], $this->headers('u1', 'clave-1000001'))->assertOk());
        $this->check('redemption-roulette-points', $this->redeem($byPrize['pts'], $this->headers('u2', 'clave-2000001'))->assertOk());
        $this->check('redemption-roulette-extra-spin', $this->redeem($byPrize['extra'], $this->headers('u3', 'clave-3000001'))->assertOk());
    }

    public function test_ejemplos_del_giro_extra(): void
    {
        // 9 posiciones de p10k: 7 códigos + 2 de reserva. Un solo premio hace que la FORMA del ejemplo no dependa de la baraja (un «sin premio» no trae `amount`);
        // el padre es manual (concede los giros).
        $b = $this->rf->activeReserveBatch([['prize_id' => 'p10k', 'quantity' => 9]], 2);
        $parent = $this->manualParent($b['batch'], 'host-a:ana', 3);
        $spin = fn (string $parent, string $key, string $user = 'ana') => $this->postJson('/v1/spins', ['parentRedemptionId' => $parent], HostTokens::bearer($user) + ['Idempotency-Key' => $key]);

        $this->check('redemption-roulette-spin', $spin($parent, 'giro-0000001')->assertOk());
        $this->check('error-spin-not-found', $spin($parent, 'giro-0000002', 'beto')->assertStatus(404));
        $spin($parent, 'giro-0000003')->assertOk();
        // La reserva (2 casillas) se agotó aunque el padre aún tenía un giro concedido: error explícito, nunca un premio inventado.
        $this->check('error-spin-reserve-exhausted', $spin($parent, 'giro-0000004')->assertStatus(409));
        $single = $this->manualParent($b['batch'], 'host-a:cata', 1);
        $this->postJson('/v1/spins', ['parentRedemptionId' => $single], HostTokens::bearer('cata') + ['Idempotency-Key' => 'giro-0000005'])->assertStatus(409);
    }

    /** Un padre que concede `$granted` giros (se reclama un código libre y se registra como lo haría el canje). */
    private function manualParent(string $batch, string $user, int $granted): string
    {
        $code = DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL ORDER BY idx LIMIT 1', [$batch])->id;
        DB::update('UPDATE codes SET redeemed_at = now(), redeemed_by = ? WHERE id = ?', [$user, $code]);
        $id = (string) Str::uuid();
        DB::insert(
            "INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response, spins_granted) VALUES (?, ?, ?, ?, ?, 'extra', '{}'::json, ?)",
            [$id, $code, $batch, $user, 'padre-'.Str::random(10), $granted],
        );

        return $id;
    }

    public function test_ejemplos_del_raspa(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1), 'scratch', 'showPrevious');
        $code = array_search('p10k', $b['prizeOf'], true);
        $this->check('redemption-scratch-win', $this->redeem($code)->assertOk());
        $this->check('redemption-scratch-none', $this->redeem(array_search('none', $b['prizeOf'], true), $this->headers('beto', 'clave-0000002'))->assertOk());
        // Mismo usuario, otra clave, campaña showPrevious: el resultado anterior con alreadyPlayed = true.
        $again = $this->redeem($code, $this->headers('ana', 'clave-0000099'))->assertOk();
        $this->assertTrue($again->json('alreadyPlayed'));
        $this->check('redemption-scratch-already-played', $again);
    }

    public function test_ejemplos_de_concentrese(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1), 'memory', 'showPrevious');
        $this->check('redemption-memory-win', $this->redeem(array_search('p10k', $b['prizeOf'], true))->assertOk());
        $this->check('redemption-memory-none', $this->redeem(array_search('none', $b['prizeOf'], true), $this->headers('beto', 'clave-0000002'))->assertOk());
    }

    public function test_ejemplos_de_dados(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1), 'dice', 'showPrevious');
        $this->check('redemption-dice-win', $this->redeem(array_search('p50k', $b['prizeOf'], true))->assertOk());
        $this->check('redemption-dice-none', $this->redeem(array_search('none', $b['prizeOf'], true), $this->headers('beto', 'clave-0000002'))->assertOk());
    }

    public function test_ejemplo_de_saldo(): void
    {
        config(['gamification.demo.initial_balance' => 120000]);
        $this->check('balance', $this->getJson('/v1/balance', HostTokens::bearer())->assertOk());
    }

    // --- Errores ---------------------------------------------------------------------------------------------------------

    public function test_ejemplos_de_errores(): void
    {
        $b = $this->rf->activeBatch();
        $this->check('error-invalid-code', $this->redeem(RedeemFixtures::unknownCode(), $this->headers('e1', 'clave-e100001'))->assertStatus(422));

        $this->redeem($b['codes'][0], $this->headers('e2', 'clave-e200001'))->assertOk();
        $this->check('error-already-used', $this->redeem($b['codes'][0], $this->headers('e3', 'clave-e300001'))->assertStatus(409));

        $this->check('error-idempotency-key-reuse', $this->redeem($b['codes'][1], $this->headers('e2', 'clave-e200001'))->assertStatus(422));

        $this->check('error-idempotency-key-required', $this->postJson('/v1/codes/redeem', ['code' => 'x'], HostTokens::bearer())->assertStatus(400));
        $this->check('error-unauthenticated', $this->postJson('/v1/codes/redeem', ['code' => 'x'], ['Idempotency-Key' => 'clave-0000001'])->assertStatus(401));

        config(['gamification.redeem.user.max_failures' => 2, 'gamification.redeem.user.lockout_base_seconds' => 60]);
        foreach ([1, 2] as $i) {
            $this->redeem(RedeemFixtures::unknownCode("rl$i"), $this->headers('e4', "clave-e40000$i"));
        }
        $this->check('error-rate-limited', $this->redeem($b['codes'][2], $this->headers('e4', 'clave-e400009'))->assertStatus(429));

        $this->rf->f->lifecycle()->close($b['batch'], 'tester');
        $this->check('error-expired', $this->redeem($b['codes'][3], $this->headers('e5', 'clave-e500001'))->assertStatus(410));

        $inactive = $this->rf->f->draft();
        $codes = $this->rf->generateCodes($inactive);
        $this->rf->f->lifecycle()->commit($inactive, 'tester');
        $this->check('error-campaign-inactive', $this->redeem($codes[0], $this->headers('e6', 'clave-e600001'))->assertStatus(403));

        config(['app.debug' => false]);
        $this->app->bind(RedeemService::class, fn () => throw new \RuntimeException('secreto interno'));
        $this->check('error-server-error', $this->redeem($b['codes'][4], $this->headers('e7', 'clave-e700001'))->assertStatus(500));
    }

    public function test_los_ejemplos_de_error_cubren_todos_los_codigos_que_emite_el_servicio(): void
    {
        $dir = $this->dir();
        $codes = [];
        foreach (glob($dir.'/error-*.json') ?: [] as $file) {
            $codes[] = json_decode((string) file_get_contents($file), true)['error']['code'];
        }
        sort($codes);
        $esperados = [
            RedeemFailure::ALREADY_USED, RedeemFailure::CAMPAIGN_INACTIVE, RedeemFailure::EXPIRED, RedeemFailure::INVALID,
            RedeemFailure::KEY_REUSE, RedeemFailure::RATE_LIMITED, 'idempotencyKeyRequired', 'serverError', 'unauthenticated',
            RedeemFailure::SPIN_NOT_FOUND, RedeemFailure::RESERVE_EXHAUSTED,
        ];
        sort($esperados);
        $this->assertSame($esperados, $codes, 'debe haber un ejemplo por cada código de error documentado');
    }
}
