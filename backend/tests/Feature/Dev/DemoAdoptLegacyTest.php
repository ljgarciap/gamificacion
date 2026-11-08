<?php

namespace Tests\Feature\Dev;

use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/** Adopción de las campañas anteriores de un servidor de demostración (SPEC-006): manual, explícita y solo con GAMIF_DEMO_ALLOWED. */
final class DemoAdoptLegacyTest extends DbTestCase
{
    private function legacyCampaign(): string
    {
        $id = (new Fixtures)->campaign('roulette');
        DB::update('UPDATE campaigns SET is_demo = false WHERE id = ?', [$id]);

        return $id;
    }

    private function isDemo(string $id): bool
    {
        return (bool) DB::selectOne('SELECT is_demo FROM campaigns WHERE id = ?', [$id])->is_demo;
    }

    public function test_se_niega_en_un_servidor_que_no_es_de_demostracion(): void
    {
        $id = $this->legacyCampaign();
        config(['gamification.demo.allowed' => false]);
        $this->artisan('gamification:demo:adopt-legacy', ['--yes' => true])->expectsOutputToContain('solo corre en un servidor de demostración')->assertFailed();
        $this->assertFalse($this->isDemo($id));
    }

    public function test_sin_yes_lista_y_no_toca_nada(): void
    {
        $id = $this->legacyCampaign();
        config(['gamification.demo.allowed' => true]);
        $this->artisan('gamification:demo:adopt-legacy')->expectsOutputToContain($id)->assertFailed();
        $this->assertFalse($this->isDemo($id));
    }

    public function test_con_yes_marca_las_campanas_y_lo_deja_en_la_bitacora(): void
    {
        $a = $this->legacyCampaign();
        $b = $this->legacyCampaign();
        config(['gamification.demo.allowed' => true]);
        $this->artisan('gamification:demo:adopt-legacy', ['--yes' => true])->assertSuccessful();
        $this->assertTrue($this->isDemo($a));
        $this->assertTrue($this->isDemo($b));
        $entry = DB::selectOne("SELECT data FROM audit_log WHERE action = 'demo.legacy_adopted'");
        $this->assertNotNull($entry);
        $this->assertEqualsCanonicalizing([$a, $b], json_decode($entry->data, true)['campaigns']);
    }

    public function test_sin_campanas_pendientes_no_hace_nada(): void
    {
        config(['gamification.demo.allowed' => true]);
        $this->artisan('gamification:demo:adopt-legacy', ['--yes' => true])->expectsOutputToContain('No hay campañas por adoptar')->assertSuccessful();
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM audit_log WHERE action = 'demo.legacy_adopted'")->n);
    }
}
