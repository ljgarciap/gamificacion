<?php

use App\Http\ApiError;
use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\AdminCan;
use App\Http\Middleware\AdminForgery;
use App\Http\Middleware\AuthenticateHost;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Panel de administración: superficie PROPIA (sesión + CSRF), separada del JWT de los hosts. Ver docs/diseno-spec-003.md §2.
            Route::prefix('admin/api/v1')
                ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, AdminForgery::class])
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'host.auth' => AuthenticateHost::class,
            'admin.auth' => AdminAuthenticate::class,
            'admin.can' => AdminCan::class,
        ]);

        // Proxies de confianza (balanceador). El límite de intentos por IP depende de `$request->ip()`: sin esto, detrás de un balanceador todas
        // las peticiones parecen venir de la IP del proxy y 30 intentos fallidos de UNA persona bloquearían el canje de TODOS. Con `*` cualquiera
        // que llegue directo al servidor podría falsear su IP con X-Forwarded-For: úselo solo si la app NO es alcanzable sin pasar por el proxy.
        // Lista separada por comas de IP/CIDR. Por defecto vacía = no se confía en ninguna cabecera X-Forwarded-*.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('GAMIF_TRUSTED_PROXIES', '')))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies, headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->is('admin/*') || $request->expectsJson(),
        );

        // Mismo formato de error en toda la API (el cliente mapea `error.code`).
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('v1/*')) {
                $retry = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return ApiError::response('rateLimited', 'Demasiadas peticiones. Intenta de nuevo más tarde.', 429, $retry);
            }
        });
        // Laravel convierte TokenMismatchException en una HttpException 419 ANTES de llegar aquí: se reconoce por el estado.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, Request $request) {
            if ($request->is('admin/*') && $e->getStatusCode() === 419) {
                return ApiError::response('csrfMismatch', 'Token CSRF inválido o ausente.', 419);
            }
        });
        $exceptions->render(function (\Throwable $e, Request $request) {
            // Un error interno nunca filtra detalles (rutas, SQL, trazas) a quien llama.
            if (($request->is('v1/*') || $request->is('admin/*')) && ! config('app.debug') && ! $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                report($e);

                return ApiError::response('serverError', 'Error interno.', 500);
            }
        });
    })->create();
