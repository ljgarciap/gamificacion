<?php

namespace App\Providers;

use App\Domain\Auth\HostTokenVerifier;
use App\Domain\Auth\HostUser;
use App\Domain\Balance\BalanceProvider;
use App\Domain\Balance\StubBalanceProvider;
use App\Domain\Beacon\BeaconClient;
use App\Domain\Beacon\DrandBeacon;
use App\Domain\Beacon\LocalBeacon;
use App\Domain\Fulfillment\PrizeFulfillment;
use App\Domain\Fulfillment\StubFulfillment;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Baliza pública de aleatoriedad: drand por defecto, configurable en config/gamification.php.
        $this->app->singleton(BeaconClient::class, function (): BeaconClient {
            if (config('gamification.beacon.driver') === 'local') {
                // La baliza local NO es aleatoria: jamás fuera de desarrollo, y si alguien la configura en producción se descubre YA.
                if (! $this->app->environment('local', 'testing')) {
                    throw new \RuntimeException('GAMIF_BEACON=local solo se permite en entornos local/testing: no es aleatoria.');
                }

                return new LocalBeacon;
            }

            return new DrandBeacon(
                (string) config('gamification.beacon.base_url'),
                (string) config('gamification.beacon.chain_hash'),
                (int) config('gamification.beacon.timeout_seconds'),
            );
        });

        // `bind` (no singleton): lee la configuración en cada resolución, así los cambios (y las pruebas) se respetan.
        $this->app->bind(HostTokenVerifier::class, fn (): HostTokenVerifier => HostTokenVerifier::fromConfig());

        // Adaptadores de desarrollo. Una app host real los reemplaza: su billetera y su canal de acreditación (SPEC-003).
        $this->app->bind(BalanceProvider::class, StubBalanceProvider::class);
        $this->app->bind(PrizeFulfillment::class, StubFulfillment::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Las únicas sesiones son las del panel: su vencimiento por inactividad es el de los administradores.
        config(['session.lifetime' => (int) config('gamification.admin.session_idle_minutes')]);
        // En producción la cookie de sesión del panel SOLO viaja por https y no la envían sitios ajenos.
        if ($this->app->environment('production')) {
            config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        }

        // Tope general de peticiones de canje por usuario (o por IP si aún no hay usuario). Aparte del bloqueo por intentos FALLIDOS.
        RateLimiter::for('redeem', function (Request $request): Limit {
            /** @var HostUser|null $user */
            $user = $request->attributes->get('gamification_user');

            return Limit::perMinute((int) config('gamification.redeem.requests_per_minute'))->by($user?->id() ?? 'ip:'.$request->ip());
        });
    }
}
