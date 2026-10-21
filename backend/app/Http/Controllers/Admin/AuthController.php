<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminAccounts;
use App\Domain\Admin\AdminPermissions;
use App\Domain\Admin\LoginFailed;
use App\Domain\Admin\LoginThrottle;
use App\Domain\Redemption\RedeemFailure;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inicio de sesión del panel en pasos: contraseña → (cambio de contraseña inicial) → (alta del 2FA) → código del 2FA. Mientras no termina, la sesión
 * está "pendiente" (id + etapa + hora, con caducidad corta) y NO da acceso a nada. Solo al verificar el segundo factor se crea la sesión autenticada.
 */
final class AuthController
{
    public function __construct(private readonly AdminAccounts $accounts, private readonly LoginThrottle $throttle) {}

    /** GET: entrega la cookie XSRF-TOKEN (la pone el middleware); el cliente la devuelve en la cabecera X-XSRF-TOKEN. */
    public function csrf(): Response
    {
        return response()->noContent();
    }

    public function login(Request $request): JsonResponse
    {
        if ($blocked = $this->blocked($request)) {
            return $blocked;
        }
        $email = $request->input('email');
        $password = $request->input('password');
        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '' || strlen($password) > 1024) {
            return $this->invalid();
        }

        try {
            $user = $this->accounts->authenticate($email, $password, $request->ip());
        } catch (LoginFailed) {
            $this->throttle->recordFailure($request->ip());

            return $this->invalid();
        }

        // Id de sesión nuevo antes de guardar nada (anti fijación de sesión).
        $request->session()->regenerate();
        $request->session()->put('admin.pending', ['id' => (int) $user->id, 'at' => Carbon::now()->getTimestamp()]);

        return response()->json(['next' => $this->stage($user)], 200, ['Cache-Control' => 'no-store']);
    }

    /** Cambio de contraseña: en el inicio de sesión (la temporal) o con la sesión completa. */
    public function password(Request $request): JsonResponse
    {
        if ($blocked = $this->blocked($request)) {
            return $blocked;
        }
        $current = $request->input('current');
        $new = $request->input('new');
        if (! is_string($current) || ! is_string($new)) {
            return $this->invalid();
        }

        $loggedIn = $this->loggedInUser($request);
        $user = $loggedIn ?? $this->pendingUser($request, ['password']);
        if ($user === null) {
            return $this->unauthenticated();
        }

        try {
            $errors = $this->accounts->changePassword($user, $current, $new, $loggedIn !== null ? $request->session()->getId() : null);
        } catch (LoginFailed) {
            $this->throttle->recordFailure($request->ip());

            return $this->invalid();
        }
        if ($errors !== []) {
            return ApiError::response('weakPassword', implode(' ', $errors), 422);
        }

        if ($loggedIn !== null) {
            return response()->json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
        }

        return response()->json(['next' => $this->stage($this->accounts->find((int) $user->id))], 200, ['Cache-Control' => 'no-store']);
    }

    public function enrollStart(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request, ['enroll']);
        if ($user === null) {
            return $this->unauthenticated();
        }
        $enrollment = $this->accounts->beginEnrollment($user);

        return response()->json($enrollment, 200, ['Cache-Control' => 'no-store']);
    }

    public function enrollConfirm(Request $request): JsonResponse
    {
        if ($blocked = $this->blocked($request)) {
            return $blocked;
        }
        $user = $this->pendingUser($request, ['enroll']);
        $code = $request->input('code');
        if ($user === null) {
            return $this->unauthenticated();
        }
        $recovery = is_string($code) ? $this->accounts->confirmEnrollment($user, $code, Carbon::now()->getTimestamp()) : null;
        if ($recovery === null) {
            $this->accounts->recordFailure($user, $request->ip(), 'mfa_enroll');
            $this->throttle->recordFailure($request->ip());

            return $this->invalid('invalidCode', 'Código incorrecto.');
        }

        $this->complete($request, $user);

        return response()->json(['recoveryCodes' => $recovery, 'user' => $this->userPayload($this->accounts->find((int) $user->id))], 200, ['Cache-Control' => 'no-store']);
    }

    public function mfa(Request $request): JsonResponse
    {
        if ($blocked = $this->blocked($request)) {
            return $blocked;
        }
        $user = $this->pendingUser($request, ['mfa']);
        $code = $request->input('code');
        if ($user === null) {
            return $this->unauthenticated();
        }
        try {
            if (! is_string($code) || strlen($code) > 64) {
                throw new LoginFailed;
            }
            $this->accounts->verifyMfa($user, $code, Carbon::now()->getTimestamp(), $request->ip());
        } catch (LoginFailed) {
            $this->throttle->recordFailure($request->ip());

            return $this->invalid('invalidCode', 'Código incorrecto.');
        }

        $this->complete($request, $user);

        return response()->json(['user' => $this->userPayload($this->accounts->find((int) $user->id))], 200, ['Cache-Control' => 'no-store']);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->attributes->get('admin_user')), 200, ['Cache-Control' => 'no-store']);
    }

    // --- Internos ---------------------------------------------------------------------------------------------------------

    private function complete(Request $request, object $user): void
    {
        Auth::guard('admin')->loginUsingId($user->id); // migra el id de sesión (anti fijación)
        $request->session()->forget('admin.pending');
        $request->session()->put('admin.mfa_verified_at', Carbon::now()->getTimestamp());
        $request->session()->put('admin.login_at', Carbon::now()->getTimestamp());
        $this->accounts->completeLogin($user, $request->ip());
    }

    /** Etapa siguiente del inicio de sesión de esa cuenta. */
    private function stage(object $user): string
    {
        return $user->must_change_password ? 'password' : ($user->totp_confirmed_at === null ? 'enroll' : 'mfa');
    }

    /** La cuenta de la sesión pendiente, solo si no caducó, sigue activa y está en una de las etapas esperadas. */
    private function pendingUser(Request $request, array $stages): ?object
    {
        $pending = $request->session()->get('admin.pending');
        if (! is_array($pending) || Carbon::now()->getTimestamp() - (int) ($pending['at'] ?? 0) > (int) config('gamification.admin.pending_ttl_seconds')) {
            $request->session()->forget('admin.pending');

            return null;
        }
        $user = $this->accounts->find((int) $pending['id']);
        if ($user === null || ! $user->active || ! in_array($this->stage($user), $stages, true)) {
            return null;
        }

        return $user;
    }

    private function loggedInUser(Request $request): ?object
    {
        $id = Auth::guard('admin')->id();
        if ($id === null || $request->session()->get('admin.mfa_verified_at') === null) {
            return null;
        }
        $user = $this->accounts->find((int) $id);

        return $user !== null && $user->active ? $user : null;
    }

    private function blocked(Request $request): ?JsonResponse
    {
        try {
            $this->throttle->assertAllowed($request->ip());
        } catch (RedeemFailure $e) {
            return ApiError::response('rateLimited', 'Demasiados intentos. Espera un momento.', 429, $e->retryAfter);
        }

        return null;
    }

    /** El MISMO error para cualquier fallo de credenciales: no se revela cuál fue. */
    private function invalid(string $code = 'invalidCredentials', string $message = 'Credenciales incorrectas.'): JsonResponse
    {
        return ApiError::response($code, $message, 401);
    }

    private function unauthenticated(): JsonResponse
    {
        return ApiError::response('unauthenticated', 'No autenticado.', 401);
    }

    /** @return array<string, mixed> */
    private function userPayload(object $user): array
    {
        return [
            'id' => (int) $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $user->role,
            'permissions' => AdminPermissions::for($user->role),
        ];
    }
}
