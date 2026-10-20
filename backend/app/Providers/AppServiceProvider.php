<?php

namespace App\Providers;

use App\Domain\Beacon\BeaconClient;
use App\Domain\Beacon\DrandBeacon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Baliza pública de aleatoriedad: drand por defecto, configurable en config/gamification.php.
        $this->app->singleton(BeaconClient::class, fn (): BeaconClient => new DrandBeacon(
            (string) config('gamification.beacon.base_url'),
            (string) config('gamification.beacon.chain_hash'),
            (int) config('gamification.beacon.timeout_seconds'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
