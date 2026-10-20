<?php

use App\Http\ApiError;
use App\Http\Middleware\AuthenticateHost;
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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['host.auth' => AuthenticateHost::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        // Mismo formato de error en toda la API (el cliente mapea `error.code`).
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('v1/*')) {
                $retry = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return ApiError::response('rateLimited', 'Demasiadas peticiones. Intenta de nuevo más tarde.', 429, $retry);
            }
        });
        $exceptions->render(function (\Throwable $e, Request $request) {
            // Un error interno nunca filtra detalles (rutas, SQL, trazas) a quien llama.
            if ($request->is('v1/*') && ! config('app.debug') && ! $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                report($e);

                return ApiError::response('serverError', 'Error interno.', 500);
            }
        });
    })->create();
