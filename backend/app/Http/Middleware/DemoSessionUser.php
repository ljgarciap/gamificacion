<?php

namespace App\Http\Middleware;

use App\Domain\Auth\HostUser;
use App\Domain\Demo\DemoAccess;
use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** La tienda solo atiende sesiones simuladas abiertas con `/v1/demo/session`, no a un usuario cualquiera del emisor abierto ni a un host real. */
final class DemoSessionUser
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var HostUser|null $user */
        $user = $request->attributes->get('gamification_user');
        if ($user === null || ! DemoAccess::isDemoSubjectUser($user->id())) {
            return ApiError::response('demoSessionInvalid', 'Abre una sesión de la muestra primero.', 403);
        }

        return $next($request);
    }
}
