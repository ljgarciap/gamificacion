<?php

namespace App\Http\Middleware;

use App\Domain\Admin\AdminPermissions;
use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `admin.can:<permiso>`: la autoridad es SIEMPRE el servidor. Toda ruta del panel declara el permiso que exige (lo comprueba una prueba). */
final class AdminCan
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->attributes->get('admin_user');
        if ($user === null || ! AdminPermissions::allows($user->role, $permission)) {
            return ApiError::response('forbidden', 'No tienes permiso para esta acción.', 403);
        }

        return $next($request);
    }
}
