<?php

namespace App\Http\Middleware;

use App\Domain\Admin\AdminAccounts;
use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige una sesión del panel COMPLETA: autenticada, con el segundo factor verificado en ESTA sesión, no vencida y de una cuenta que sigue activa y sin
 * cambio de contraseña pendiente. La cuenta se relee de la base en cada petición: desactivar o degradar a alguien surte efecto de inmediato.
 */
final class AdminAuthenticate
{
    public function __construct(private readonly AdminAccounts $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');
        $id = $guard->id();
        $verifiedAt = $request->session()->get('admin.mfa_verified_at');
        $loginAt = (int) $request->session()->get('admin.login_at', 0);
        $maxAge = (int) config('gamification.admin.session_absolute_hours') * 3600;

        $user = $id === null ? null : $this->accounts->find((int) $id);
        if ($user === null || $verifiedAt === null || ! $user->active || $user->must_change_password
            || ($loginAt > 0 && Carbon::now()->getTimestamp() - $loginAt > $maxAge)) {
            if ($id !== null) {
                $guard->logout();
                $request->session()->invalidate();
            }

            return ApiError::response('unauthenticated', 'No autenticado.', 401);
        }

        $request->attributes->set('admin_user', $user);

        return $next($request);
    }
}
