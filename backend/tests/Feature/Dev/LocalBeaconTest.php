<?php

namespace Tests\Feature\Dev;

use App\Domain\Audit\AuditLog;
use App\Domain\Batches\BatchLifecycle;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Beacon\DrandBeacon;
use App\Domain\Beacon\LocalBeacon;
use App\Domain\Crypto\PrizeVault;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

final class LocalBeaconTest extends DbTestCase
{
    private function resolve(string $driver): BeaconClient
    {
        config(['gamification.beacon.driver' => $driver]);
        $this->app->forgetInstance(BeaconClient::class);

        return $this->app->make(BeaconClient::class);
    }

    public function test_por_defecto_se_usa_drand(): void
    {
        $this->assertInstanceOf(DrandBeacon::class, $this->resolve('drand'));
    }

    public function test_la_baliza_local_se_acepta_en_local_y_testing(): void
    {
        $this->assertInstanceOf(LocalBeacon::class, $this->resolve('local'));
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertInstanceOf(LocalBeacon::class, $this->resolve('local'));
    }

    public function test_la_baliza_local_se_rechaza_en_produccion_y_en_staging(): void
    {
        foreach (['production', 'staging', 'qa'] as $env) {
            $this->app->detectEnvironment(fn () => $env);
            try {
                $this->resolve('local');
                $this->fail("debía rechazarla en $env");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('no es aleatoria', $e->getMessage(), $env);
            }
        }
    }

    public function test_el_valor_es_determinista_distinto_por_ronda_y_de_32_bytes(): void
    {
        $b = new LocalBeacon;
        $this->assertSame($b->valueForRound(5), $b->valueForRound(5));
        $this->assertNotSame($b->valueForRound(5), $b->valueForRound(6));
        $this->assertSame(32, strlen($b->valueForRound(5)));
        $this->assertSame($b->chainHash(), (new LocalBeacon)->chainHash());
        $this->assertNotNull($b->valueForRound(PHP_INT_MAX - 1), 'siempre disponible: no hay que esperar');
    }

    public function test_con_la_baliza_local_el_ciclo_completo_no_espera(): void
    {
        $f = new Fixtures;
        $id = $f->draftWithCodes();
        $local = new BatchLifecycle(new LocalBeacon, new PrizeVault, new AuditLog);
        $local->commit($id, 'tester');
        $local->activate($id, 'tester'); // sin BeaconNotReady: la baliza local siempre tiene valor

        $this->assertSame('active', DB::table('batches')->where('id', $id)->value('state'));
    }
}
