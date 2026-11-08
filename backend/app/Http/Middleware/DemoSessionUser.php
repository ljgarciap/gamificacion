<?php

namespace App\Http\Middleware;

use App\Domain\Auth\HostUser;
use App\Domain\Demo\DemoAccess;
use App\Domain\Demo\DemoAccounts;
use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La tienda solo atiende sesiones de la muestra de una CUENTA VIVA (SPEC-006A), no a un usuario cualquiera del emisor abierto ni a un host real.
 * Con la cuenta borrada, un token aún vigente deja de servir de inmediato.
 */
final class DemoSessionUser
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var HostUser|null $user */
        $user = $request->attributes->get('gamification_user');
        if ($user === null || ! DemoAccess::isDemoSubjectUser($user->id())
            || ! app(DemoAccounts::class)->existsForSubject(substr($user->id(), strlen(DemoAccess::issuerPrefix())))) {
            return ApiError::response('demoSessionInvalid', 'Ingresa a la muestra con tu correo primero.', 403);
        }

        return $next($request);
    }
}
