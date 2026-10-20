<?php

namespace App\Http\Middleware;

use App\Domain\Auth\HostTokenVerifier;
use App\Domain\Auth\TokenInvalid;
use App\Http\ApiError;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica la petición con el JWT de la app host y deja el usuario en `gamification_user`. El usuario sale SOLO del token.
 * Implementa `AuthenticatesRequests` (interfaz marcadora): Laravel ordena estos middleware ANTES del limitador de peticiones, que necesita
 * saber quién es el usuario para contar por usuario y no por IP.
 *
 * Al cliente nunca se le dice POR QUÉ falló (token vencido, firma, emisor...): solo "no autenticado". El motivo va al registro.
 */
final class AuthenticateHost implements AuthenticatesRequests
{
    public function __construct(private readonly HostTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return $this->unauthenticated();
        }

        try {
            $user = $this->verifier->verify($token);
        } catch (TokenInvalid $e) {
            Log::notice('Token de host rechazado', ['motivo' => $e->getMessage(), 'ip' => $request->ip()]);

            return $this->unauthenticated();
        }

        $request->attributes->set('gamification_user', $user);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return ApiError::response('unauthenticated', 'No autenticado.', 401, headers: ['WWW-Authenticate' => 'Bearer']);
    }
}
