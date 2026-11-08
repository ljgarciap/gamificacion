<?php

namespace App\Http\Middleware;

use App\Domain\Demo\DemoSwitch;
use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Las rutas de la muestra existen siempre pero, con la muestra apagada o caducada, responden 404 como si no existieran (SPEC-006 CA-1). */
final class DemoActive
{
    public function __construct(private readonly DemoSwitch $switch) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->switch->isActive()) {
            return ApiError::response('notFound', 'No encontrado.', 404);
        }

        return $next($request);
    }
}
