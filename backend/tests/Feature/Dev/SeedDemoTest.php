<?php

namespace Tests\Feature\Dev;

use App\Domain\Audit\AuditLog;
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

    public function test_crea_la_ruleta_y_el_raspa_con_sus_lotes_activos(): void
    {
        $this->artisan('gamification:demo:seed')->assertSuccessful();

        $campaigns = DB::table('campaigns')->orderBy('name')->get();
        $this->assertSame(['Gira y Gana', 'Raspa y Gana'], $campaigns->pluck('name')->all());
        $this->assertSame(['roulette', 'scratch'], $campaigns->pluck('game_type')->all());
        $this->assertSame(['rejectOnly', 'showPrevious'], $campaigns->pluck('used_code_behavior')->all());

        $this->assertSame(['active', 'active'], DB::table('batches')->pluck('state')->all());
        $this->assertSame(80, DB::table('codes')->count());
        $this->assertCount(80, DemoCodes::load());
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_los_codigos_son_reales_y_su_descripcion_coincide_con_el_premio_que_entregan(): void
    {
        $this->artisan('gamification:demo:seed')->assertSuccessful();
        $service = (new RedeemFixtures)->service();

        foreach (DemoCodes::load() as $i => $entry) {
            if ($i % 4 !== 0) {
                continue; // una muestra: 20 de 80
            }
            $r = $service->redeem('host-a:u'.$i, 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $entry['code'])->toArray();
            $this->assertSame(
                preg_replace('/^(Ruleta|Raspa): /', '', $entry['description']),
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

        $delivered = [];
        foreach (array_filter(DemoCodes::load(), fn ($e) => $e['game'] === 'roulette') as $i => $entry) {
            $label = $service->redeem('host-a:r'.$i, 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $entry['code'])->toArray()['outcome']['prize']['label'];
            $delivered[$label] = ($delivered[$label] ?? 0) + 1;
        }
        ksort($delivered);

        $esperado = ['$ 10.000' => 10, '$ 100.000' => 1, '$ 50.000' => 2, '250 puntos' => 5, 'Cupón 20%' => 3, 'Gira de nuevo' => 1, 'Sin premio' => 18];
        ksort($esperado);
        $this->assertSame($esperado, $delivered);
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
        $this->assertSame(4, DB::table('batches')->count());
        $this->assertCount(160, DemoCodes::load());
        $this->assertCount(160, array_unique(array_column(DemoCodes::load(), 'code')));
    }

    public function test_se_niega_a_correr_fuera_de_desarrollo(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('gamification:demo:seed')->expectsOutputToContain('solo se ejecuta en entornos local/testing')->assertFailed();
        $this->assertSame(0, DB::table('campaigns')->count());
        $this->assertFileDoesNotExist(DemoCodes::path());
    }
}
