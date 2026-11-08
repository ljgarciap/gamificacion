<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\SecretBox;
use App\Domain\Audit\AuditLog;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoRuns;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoSwitch;
use Illuminate\Support\Facades\DB;
use Tests\Support\RedeemFixtures;

/** Muestra real: sembrado del pool, corridas y reinicio (SPEC-006 M2; CA-6/CA-7/CA-10, amenazas T-D6, T-D8). */
final class DemoSeedTest extends AdminTestCase
{
    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'gamification.demo.allowed' => true,
            'gamification.hosts.'.config('gamification.dev.issuer') => ['alg' => 'HS256', 'key' => base64_encode(str_repeat('k', 32))],
        ]);
    }

    /** @return array<string, mixed> */
    private function fullConfig(): array
    {
        $c = DemoConfig::defaults();
        $c['mix'] = [
            'roulette' => ['prizes' => [['type' => 'money', 'amount' => 5000, 'count' => 2], ['type' => 'money', 'amount' => 20000, 'count' => 1], ['type' => 'points', 'amount' => 100, 'count' => 1]], 'noPrizeCount' => 6],
            'scratch' => ['prizes' => [['type' => 'money', 'amount' => 10000, 'count' => 3]], 'noPrizeCount' => 7],
            'memory' => ['prizes' => [['type' => 'points', 'amount' => 50, 'count' => 2], ['type' => 'coupon', 'amount' => 20, 'count' => 1]], 'noPrizeCount' => 5],
            'dice' => ['prizes' => [['type' => 'money', 'amount' => 10000, 'count' => 2], ['type' => 'money', 'amount' => 50000, 'count' => 1]], 'noPrizeCount' => 6],
            'bomb' => ['prizes' => [['type' => 'money', 'amount' => 10000, 'count' => 2]], 'noPrizeCount' => 8],
            'bingo' => ['prizes' => [['type' => 'money', 'amount' => 10000, 'count' => 4], ['type' => 'money', 'amount' => 50000, 'count' => 2], ['type' => 'money', 'amount' => 100000, 'count' => 1]], 'noPrizeCount' => 5],
            'poker' => ['prizes' => [['type' => 'money', 'amount' => 10000, 'count' => 3], ['type' => 'money', 'amount' => 50000, 'count' => 2], ['type' => 'money', 'amount' => 100000, 'count' => 1]], 'noPrizeCount' => 4],
        ];

        return DemoConfig::validate($c);
    }

    private function demoSeeder(): DemoSeeder
    {
        return $this->app->make(DemoSeeder::class);
    }

    private function plain(object $row): string
    {
        return (new SecretBox)->open($row->code_enc, DemoSeeder::sealContext(bin2hex($row->code_hash)));
    }

    /** @return list<object> filas con code_enc/code_hash como cadenas binarias */
    private function poolRows(string $where = 'true'): array
    {
        return array_map(function (object $r): object {
            $r->code_enc = hex2bin($r->enc);
            $r->code_hash = hex2bin($r->hash);

            return $r;
        }, DB::select("SELECT id, game, description, state, generation, batch_id, encode(code_enc, 'hex') AS enc, encode(code_hash, 'hex') AS hash FROM demo_pool WHERE $where ORDER BY id"));
    }

    private function superadmin(): array
    {
        $a = $this->makeAdmin('superadmin');
        $this->login($a);

        return $a;
    }

    // --- Siembra --------------------------------------------------------------------------------------------------

    public function test_siembra_los_siete_juegos_con_la_mezcla_exacta_y_codigos_reales(): void
    {
        $cfg = $this->fullConfig();
        $r = $this->demoSeeder()->seed($cfg, 'test');
        $this->assertSame(['games' => 7, 'codes' => DemoConfig::poolSize($cfg), 'generation' => 1], $r);

        $this->assertSame(7, (int) DB::selectOne('SELECT count(*) AS n FROM campaigns WHERE is_demo')->n);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM campaigns WHERE NOT is_demo')->n);
        $this->assertSame(['active'], DB::table('batches')->distinct()->pluck('state')->all());
        $rows = $this->poolRows();
        $this->assertCount(DemoConfig::poolSize($cfg), $rows);
        $this->assertSame(['available'], array_values(array_unique(array_column($rows, 'state'))));

        // La mezcla pedida es exactamente lo sembrado: por juego y por premio.
        foreach ($cfg['mix'] as $game => $entry) {
            $labels = array_count_values(array_column(array_filter($rows, fn ($x) => $x->game === $game), 'description'));
            $this->assertSame($entry['noPrizeCount'], $labels[\App\Domain\Demo\DemoGameTemplates::SHORT[$game].': Sin premio'] ?? 0, "$game sin premio");
            $this->assertSame(
                DemoConfig::poolSize(['mix' => [$game => $entry]]),
                array_sum($labels),
                "$game total",
            );
            foreach ($entry['prizes'] as $p) {
                $label = \App\Domain\Demo\DemoGameTemplates::SHORT[$game].': '.match ($p['type']) {
                    'money' => '$ '.number_format($p['amount'], 0, ',', '.'),
                    'points' => $p['amount'].' puntos',
                    default => 'Cupón '.$p['amount'].'%',
                };
                $this->assertSame($p['count'], $labels[$label] ?? 0, "$game $label");
            }
        }
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_canjear_todo_el_pool_de_un_juego_entrega_exactamente_la_mezcla(): void
    {
        $cfg = $this->fullConfig();
        $this->demoSeeder()->seed($cfg, 'test');
        $service = (new RedeemFixtures)->service();

        foreach (['scratch', 'dice', 'bingo'] as $game) {
            $delivered = [];
            foreach ($this->poolRows("game = '$game'") as $i => $row) {
                // Un código del pool solo lo canjea quien lo recibió (SPEC-006 T-D9): se asigna como lo haría la compra.
                DB::update("UPDATE demo_pool SET state = 'assigned', assigned_to = ?, assigned_at = now() WHERE id = ?", ["host-a:$game$i", $row->id]);
                $r = $service->redeem("host-a:$game$i", 'clave-'.$game.str_pad((string) $i, 6, '0', STR_PAD_LEFT), $this->plain($row))->toArray();
                $this->assertSame($game, $r['campaign']['gameType']);
                $this->assertSame(\App\Domain\Demo\DemoGameTemplates::SHORT[$game].': '.$r['outcome']['prize']['label'], $row->description, 'la descripción coincide con el premio real');
                $delivered[$row->description] = ($delivered[$row->description] ?? 0) + 1;
            }
            $this->assertSame(
                array_sum(array_map(fn ($p) => $p['count'], $cfg['mix'][$game]['prizes'])) + $cfg['mix'][$game]['noPrizeCount'],
                array_sum($delivered),
            );
        }
    }

    public function test_el_codigo_en_claro_va_cifrado_y_ligado_a_su_fila(): void
    {
        $this->demoSeeder()->seed($this->fullConfig(), 'test');
        $rows = $this->poolRows();
        $box = new SecretBox;
        foreach (array_slice($rows, 0, 5) as $row) {
            $plain = $this->plain($row);
            $this->assertMatchesRegularExpression('/^[A-Z]+-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{3}$/', $plain);
            $this->assertStringNotContainsString($plain, $row->code_enc, 'el claro no está en la columna');
        }
        // Copiar el cifrado de una fila a otra no se puede abrir (el contexto es la huella).
        $this->expectException(\RuntimeException::class);
        $box->open($rows[0]->code_enc, DemoSeeder::sealContext(bin2hex($rows[1]->code_hash)));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function variants(): array
    {
        $w = static fn (int $n, int $base = 1000): array => array_map(fn (int $i) => ['type' => 'money', 'amount' => $base * ($i + 1), 'count' => 1], range(0, $n - 1));

        return [
            'ruleta con 11 premios y sin premio' => ['roulette', ['prizes' => $w(11), 'noPrizeCount' => 3]],
            'ruleta solo sin premio' => ['roulette', ['prizes' => [], 'noPrizeCount' => 4]],
            'ruleta con un premio y nada más' => ['roulette', ['prizes' => $w(1), 'noPrizeCount' => 0]],
            'raspa con puntos y cupón' => ['scratch', ['prizes' => [['type' => 'points', 'amount' => 10, 'count' => 2], ['type' => 'coupon', 'amount' => 0, 'count' => 1]], 'noPrizeCount' => 3]],
            'concéntrese solo premios' => ['memory', ['prizes' => $w(3), 'noPrizeCount' => 0]],
            'dados con 6 premios' => ['dice', ['prizes' => $w(6), 'noPrizeCount' => 5]],
            'dados con 1 premio sin "sin premio"' => ['dice', ['prizes' => $w(1), 'noPrizeCount' => 0]],
            'dados solo sin premio' => ['dice', ['prizes' => [], 'noPrizeCount' => 5]],
            'bomba' => ['bomb', ['prizes' => $w(4), 'noPrizeCount' => 2]],
            'bingo con 1 premio' => ['bingo', ['prizes' => $w(1), 'noPrizeCount' => 4]],
            'bingo con 2 premios' => ['bingo', ['prizes' => $w(2), 'noPrizeCount' => 4]],
            'bingo con 3 premios' => ['bingo', ['prizes' => $w(3), 'noPrizeCount' => 0]],
            'póker con 1 premio' => ['poker', ['prizes' => $w(1), 'noPrizeCount' => 4]],
            'póker con 4 premios' => ['poker', ['prizes' => $w(4), 'noPrizeCount' => 4]],
            'póker con 6 premios' => ['poker', ['prizes' => $w(6), 'noPrizeCount' => 0]],
        ];
    }

    /** @param array<string, mixed> $entry */
    #[\PHPUnit\Framework\Attributes\DataProvider('variants')]
    public function test_cada_combinacion_valida_se_siembra_y_el_motor_la_acepta(string $game, array $entry): void
    {
        $cfg = DemoConfig::defaults();
        $cfg['mix'] = [$game => $entry];
        $cfg = DemoConfig::validate($cfg); // pasa el validador del panel…
        $r = $this->demoSeeder()->seed($cfg, 'test'); // …y el motor la compromete y activa sin quejarse
        $this->assertSame(DemoConfig::poolSize($cfg), $r['codes']);
        $this->assertSame('active', DB::selectOne('SELECT state FROM batches')->state);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function unplayable(): array
    {
        $w = static fn (int $n): array => array_map(fn (int $i) => ['type' => 'money', 'amount' => 1000 * ($i + 1), 'count' => 1], range(0, $n - 1));

        return [
            'ruleta con 12 premios y sin premio' => ['roulette', ['prizes' => $w(12), 'noPrizeCount' => 1]],
            'dados con 7 premios' => ['dice', ['prizes' => $w(7), 'noPrizeCount' => 1]],
            'bingo sin premios' => ['bingo', ['prizes' => [], 'noPrizeCount' => 5]],
            'bingo con 4 premios' => ['bingo', ['prizes' => $w(4), 'noPrizeCount' => 1]],
            'póker sin premios' => ['poker', ['prizes' => [], 'noPrizeCount' => 5]],
            'póker con 7 premios' => ['poker', ['prizes' => $w(7), 'noPrizeCount' => 1]],
            'premio repetido' => ['scratch', ['prizes' => [['type' => 'money', 'amount' => 1000, 'count' => 1], ['type' => 'money', 'amount' => 1000, 'count' => 2]], 'noPrizeCount' => 1]],
        ];
    }

    /** @param array<string, mixed> $entry */
    #[\PHPUnit\Framework\Attributes\DataProvider('unplayable')]
    public function test_lo_que_el_juego_no_puede_mostrar_se_rechaza_al_guardar_no_al_sembrar(string $game, array $entry): void
    {
        $this->superadmin();
        $cfg = DemoConfig::defaults();
        $cfg['mix'] = [$game => $entry];
        $r = $this->api('PUT', '/demo/config', $cfg);
        $this->assertSame(422, $r->status(), $r->getContent());
        $this->assertStringContainsString($game, $r->json('error.message'));
    }

    // --- Corridas --------------------------------------------------------------------------------------------------

    private function configured(): void
    {
        $this->assertSame(200, $this->api('PUT', '/demo/config', $this->fullConfig())->status());
    }

    public function test_pedir_una_corrida_exige_servidor_demo_y_configuracion_y_solo_una_a_la_vez(): void
    {
        $this->superadmin();
        $this->assertSame(409, $this->api('POST', '/demo/seed')->status(), 'sin configuración');
        $this->configured();
        config(['gamification.demo.allowed' => false]);
        $this->assertSame(409, $this->api('POST', '/demo/seed')->status(), 'servidor no demo');
        config(['gamification.demo.allowed' => true]);

        $r = $this->api('POST', '/demo/seed');
        $this->assertSame(202, $r->status());
        $id = $r->json('runId');
        $this->assertSame('pending', $this->api('GET', "/demo/runs/$id")->json('state'));
        $dup = $this->api('POST', '/demo/reset', ['accounts' => 'keep']);
        $this->assertSame(409, $dup->status());
        $this->assertSame('alreadyPending', $dup->json('error.code'));
        $this->assertSame(404, $this->api('GET', '/demo/runs/99999')->status());
    }

    public function test_el_proceso_ejecuta_la_corrida_y_no_se_siembra_dos_veces(): void
    {
        $this->superadmin();
        $this->configured();
        $id = $this->api('POST', '/demo/seed')->json('runId');
        $this->artisan('gamification:demo:process')->assertSuccessful();

        $run = $this->api('GET', "/demo/runs/$id")->json();
        $this->assertSame('done', $run['state']);
        $this->assertNull($run['error']);
        $this->assertNotNull($run['finishedAt']);
        $pool = $this->api('GET', '/demo')->json('pool');
        $this->assertSame(DemoConfig::poolSize($this->fullConfig()), $pool['available']);
        $this->assertSame($id, $this->api('GET', '/demo')->json('lastRun.id'));

        $again = $this->api('POST', '/demo/seed');
        $this->assertSame(409, $again->status(), 'ya hay códigos disponibles');
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM demo_runs')->n);
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_el_proceso_no_siembra_encima_de_un_pool_con_codigos_disponibles(): void
    {
        $this->superadmin();
        $this->configured();
        $this->demoSeeder()->seed($this->fullConfig(), 'test');
        $before = (int) DB::selectOne('SELECT count(*) AS n FROM demo_pool')->n;
        // Una corrida pedida antes de que otra sembrara (carrera): el proceso la revisa de nuevo y no duplica el pool.
        $id = (new DemoRuns)->request('seed', 'admin:1');
        $this->artisan('gamification:demo:process')->assertFailed();
        $this->assertSame('failed', (new DemoRuns)->find($id)['state']);
        $this->assertStringContainsString('Reiniciar', (new DemoRuns)->find($id)['error']);
        $this->assertSame($before, (int) DB::selectOne('SELECT count(*) AS n FROM demo_pool')->n);
    }

    public function test_una_corrida_que_falla_queda_registrada_y_libera_el_panel(): void
    {
        $this->superadmin();
        $this->configured();
        // Alguien dejó una configuración que ya no pasa el validador (p. ej. cambió un techo): la corrida falla con un mensaje claro.
        DB::update("UPDATE demo_config SET config = '{\"user\": {}}'::jsonb WHERE id = 1");
        $id = $this->api('POST', '/demo/seed')->json('runId');
        $this->artisan('gamification:demo:process')->assertFailed();

        $run = $this->api('GET', "/demo/runs/$id")->json();
        $this->assertSame('failed', $run['state']);
        $this->assertNotEmpty($run['error']);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_pool')->n);
        $this->assertSame(202, $this->api('POST', '/demo/seed')->status(), 'tras fallar se puede pedir otra');
    }

    public function test_una_corrida_abandonada_se_da_por_fallida_y_no_bloquea_para_siempre(): void
    {
        $this->superadmin();
        $this->configured();
        $id = $this->api('POST', '/demo/seed')->json('runId');
        DB::update("UPDATE demo_runs SET state = 'running', started_at = now() - interval '29 minutes' WHERE id = ?", [$id]);
        $this->assertSame(0, (new DemoRuns)->failStale(), 'aún dentro del plazo');
        $this->assertSame(409, $this->api('POST', '/demo/reset', ['accounts' => 'keep'])->status());
        DB::update("UPDATE demo_runs SET started_at = now() - interval '31 minutes' WHERE id = ?", [$id]);
        $this->artisan('gamification:demo:process')->assertSuccessful();
        $this->assertSame('failed', $this->api('GET', "/demo/runs/$id")->json('state'));
        $this->assertSame(202, $this->api('POST', '/demo/reset', ['accounts' => 'keep'])->status());
    }

    public function test_reiniciar_apaga_retira_el_pool_viejo_cierra_sus_lotes_y_siembra_uno_nuevo(): void
    {
        $this->superadmin();
        $this->configured();
        $this->api('POST', '/demo/seed');
        $this->artisan('gamification:demo:process')->assertSuccessful();
        $this->assertSame(204, $this->api('POST', '/demo/enable')->status());
        $oldBatches = DB::table('batches')->pluck('id')->all();
        $total = DemoConfig::poolSize($this->fullConfig());

        $this->assertSame(202, $this->api('POST', '/demo/reset', ['accounts' => 'keep'])->status());
        $this->artisan('gamification:demo:process')->assertSuccessful();

        $this->assertFalse((new DemoSwitch)->row()['enabled'], 'queda apagada: hay que volver a encenderla');
        $this->assertSame($total, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'retired' AND generation = 1")->n);
        $this->assertSame($total, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available' AND generation = 2")->n);
        $this->assertSame(['closed'], DB::table('batches')->whereIn('id', $oldBatches)->distinct()->pluck('state')->all());
        $this->assertSame(['active'], DB::table('batches')->whereNotIn('id', $oldBatches)->distinct()->pluck('state')->all());
        $this->assertSame(14, (int) DB::selectOne('SELECT count(*) AS n FROM batches')->n);
        $this->assertNull((new AuditLog)->verifyChain());
        $this->assertSame(204, $this->api('POST', '/demo/enable')->status(), 'con el pool nuevo se puede encender');
    }

    public function test_el_proceso_apaga_la_muestra_caducada(): void
    {
        $this->superadmin();
        $this->configured();
        $this->api('POST', '/demo/seed');
        $this->artisan('gamification:demo:process')->assertSuccessful();
        $this->api('POST', '/demo/enable')->assertNoContent();
        $this->advance(24 * 3600 + 60);
        $this->artisan('gamification:demo:process')->assertSuccessful();
        $this->assertFalse((new DemoSwitch)->row()['enabled']);
    }

    public function test_solo_el_superadmin_pide_corridas(): void
    {
        foreach (['operator', 'auditor'] as $role) {
            $this->login($this->makeAdmin($role));
            foreach ([['POST', '/demo/seed'], ['POST', '/demo/reset'], ['GET', '/demo/runs/1']] as [$m, $p]) {
                $this->assertSame(403, $this->api($m, $p, [])->status(), "$role $m $p");
            }
            $this->api('POST', '/auth/logout');
        }
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM demo_runs')->n);
    }
}
