<?php

namespace Tests\Feature\Dev;

use App\Domain\Audit\AuditLog;
use App\Domain\Crypto\PrizeVault;
use App\Domain\Dev\DemoCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\DbTestCase;
use Tests\Support\RedeemFixtures;

final class SeedDemoTest extends DbTestCase
{
    private string $storage;

    protected function extraEnv(): array
    {
        return ['GAMIF_BEACON' => 'local'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/seed-demo-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_crea_los_cuatro_juegos_con_sus_lotes_activos(): void
    {
        $this->artisan('gamification:demo:seed')->assertSuccessful();

        $campaigns = DB::table('campaigns')->orderBy('name')->get();
        $this->assertSame(['Concéntrese', 'Dados de la suerte', 'Gira y Gana', 'Raspa y Gana'], $campaigns->pluck('name')->all());
        $this->assertSame(['memory', 'dice', 'roulette', 'scratch'], $campaigns->pluck('game_type')->all());
        $this->assertSame(['showPrevious', 'showPrevious', 'rejectOnly', 'showPrevious'], $campaigns->pluck('used_code_behavior')->all());

        $this->assertSame(['active', 'active', 'active', 'active'], DB::table('batches')->pluck('state')->all());
        $this->assertSame(138, DB::table('codes')->count());
        $this->assertCount(138, DemoCodes::load());
        $this->assertSame(['dice' => 30, 'memory' => 30, 'roulette' => 38, 'scratch' => 40], array_map('count', $this->byGame()));
        $this->assertNull((new AuditLog)->verifyChain());
    }

    /** @return array<string, list<array{code: string, game: string, description: string, batch_id: string}>> */
    private function byGame(): array
    {
        $out = [];
        foreach (DemoCodes::load() as $entry) {
            $out[$entry['game']][] = $entry;
        }
        ksort($out);

        return $out;
    }

    public function test_los_codigos_son_reales_y_su_descripcion_coincide_con_el_premio_que_entregan(): void
    {
        $this->artisan('gamification:demo:seed')->assertSuccessful();
        $service = (new RedeemFixtures)->service();

        foreach (DemoCodes::load() as $i => $entry) {
            if ($i % 4 !== 0) {
                continue; // una muestra
            }
            $r = $service->redeem('host-a:u'.$i, 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $entry['code'])->toArray();
            $this->assertSame(
                preg_replace('/^(Ruleta|Raspa|Concéntrese|Dados): /', '', $entry['description']),
                $r['outcome']['prize']['label'],
                "la descripción de {$entry['code']} debe coincidir con su premio",
            );
            $this->assertSame($entry['game'], $r['campaign']['gameType']);
        }
    }

    public function test_al_canjear_todos_los_codigos_de_la_ruleta_se_entrega_exactamente_el_stock(): void
    {
        $this->artisan('gamification:demo:seed')->assertSuccessful();
        $service = (new RedeemFixtures)->service();

        // 40 posiciones = 38 códigos + 2 casillas de reserva. Se canjean todos los códigos y se juegan todos los giros que concedan; lo que
        // queda sin consumir en la reserva se suma al final: el total es EXACTAMENTE el stock del pool.
        $spins = (new RedeemFixtures)->spinService();
        $delivered = [];
        $count = function (array $r) use (&$delivered): void {
            $delivered[$r['outcome']['prize']['label']] = ($delivered[$r['outcome']['prize']['label']] ?? 0) + 1;
        };
        foreach (array_filter(DemoCodes::load(), fn ($e) => $e['game'] === 'roulette') as $i => $entry) {
            $user = 'host-a:r'.$i;
            $r = $service->redeem($user, 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $entry['code']);
            $count($r->toArray());
            $pending = [[$r->redemptionId, $r->toArray()['outcome']['extraSpins']]];
            $n = 0;
            while ($pending !== []) {
                [$parent, $granted] = array_pop($pending);
                for ($g = 0; $g < $granted; $g++) {
                    $child = $spins->spin($user, 'giro-'.$i.'-'.(++$n).'-xxxx', $parent);
                    $count($child->toArray());
                    $pending[] = [$child->redemptionId, $child->toArray()['outcome']['extraSpins']];
                }
            }
        }
        $batch = DB::table('batches')->where('name', 'Demo RULETA')->value('id');
        $vault = new PrizeVault;
        $labels = DB::table('prizes')->where('campaign_id', DB::table('batches')->where('id', $batch)->value('campaign_id'))->pluck('label', 'prize_id')->all();
        foreach (DB::select("SELECT idx, encode(prize_enc,'hex') AS p FROM reserve_slots WHERE batch_id = ? AND consumed_at IS NULL", [$batch]) as $slot) {
            $label = $labels[$vault->decryptReservePrize($batch, 38 + (int) $slot->idx, hex2bin($slot->p))];
            $delivered[$label] = ($delivered[$label] ?? 0) + 1;
        }
        ksort($delivered);

        $esperado = ['$ 10.000' => 10, '$ 100.000' => 1, '$ 50.000' => 2, '250 puntos' => 5, 'Cupón 20%' => 3, 'Gira de nuevo' => 2, 'Sin premio' => 17];
        ksort($esperado);
        $this->assertSame($esperado, $delivered);
    }

    public function test_concentrese_y_dados_entregan_exactamente_su_stock_y_un_tablero_coherente_con_el_premio(): void
    {
        $this->artisan('gamification:demo:seed', ['--only' => 'memory,dice'])->assertSuccessful();
        $service = (new RedeemFixtures)->service();

        $delivered = ['memory' => [], 'dice' => []];
        foreach (DemoCodes::load() as $i => $entry) {
            $r = $service->redeem('host-a:q'.$i, 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $entry['code'])->toArray();
            $label = $r['outcome']['prize']['label'];
            $delivered[$entry['game']][$label] = ($delivered[$entry['game']][$label] ?? 0) + 1;
            $result = $r['outcome']['gameResult'];

            if ($entry['game'] === 'memory') {
                $counts = array_count_values($result['cards']);
                $this->assertCount(12, $result['cards']);
                $this->assertSame([2], array_values(array_unique($counts)));
                $this->assertSame($r['outcome']['prize']['type'] !== 'none', isset($counts[$r['outcome']['prize']['id']]), 'pareja premio solo si hay premio');
            } else {
                $legend = array_values(array_filter($r['campaign']['gameConfig']['legend'], fn ($l) => $l['prize']['id'] === $r['outcome']['prize']['id']))[0];
                $dice = $result['dice'];
                sort($dice);
                $this->assertContains($dice, $legend['rolls'], 'la combinación pertenece al premio');
            }
        }
        ksort($delivered['memory']);
        ksort($delivered['dice']);
        $this->assertSame(['$ 10.000' => 7, '$ 50.000' => 1, '250 puntos' => 4, 'Sin premio' => 18], $delivered['memory']);
        $this->assertSame(['$ 10.000' => 8, '$ 50.000' => 2, 'Sin premio' => 20], $delivered['dice']);
    }

    public function test_only_siembra_solo_los_juegos_pedidos_y_rechaza_los_desconocidos(): void
    {
        $this->artisan('gamification:demo:seed', ['--only' => 'dice'])->assertSuccessful();
        $this->assertSame(['dice'], DB::table('campaigns')->pluck('game_type')->all());

        $this->artisan('gamification:demo:seed', ['--only' => 'poker'])->expectsOutputToContain('Juego desconocido: poker')->assertFailed();
        $this->assertSame(1, DB::table('campaigns')->count());
    }

    public function test_el_texto_de_los_codigos_no_queda_en_la_base_de_datos(): void
    {
        $this->artisan('gamification:demo:seed')->assertSuccessful();
        $dump = json_encode([DB::select('SELECT * FROM audit_log'), DB::select("SELECT encode(code_hash,'hex') h, encode(prize_enc,'hex') p FROM codes")]);
        foreach (array_slice(DemoCodes::load(), 0, 10) as $entry) {
            $this->assertStringNotContainsString(substr($entry['code'], -11), $dump);
        }
    }

    public function test_ejecutarlo_dos_veces_acumula_sin_romperse(): void
    {
        $this->artisan('gamification:demo:seed')->assertSuccessful();
        $this->artisan('gamification:demo:seed')->assertSuccessful();
        $this->assertSame(8, DB::table('batches')->count());
        $this->assertCount(276, DemoCodes::load());
        $this->assertCount(276, array_unique(array_column(DemoCodes::load(), 'code')));
    }

    public function test_se_niega_a_correr_fuera_de_desarrollo(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('gamification:demo:seed')->expectsOutputToContain('solo se ejecuta en entornos local/testing')->assertFailed();
        $this->assertSame(0, DB::table('campaigns')->count());
        $this->assertFileDoesNotExist(DemoCodes::path());
    }
}
