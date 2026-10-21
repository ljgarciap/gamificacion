<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminAccounts;
use App\Http\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Administración de usuarios del panel (solo superadmin). Las contraseñas temporales se devuelven UNA vez y nunca se guardan en claro. */
final class UserController
{
    public function __construct(private readonly AdminAccounts $accounts) {}

    public function index(): JsonResponse
    {
        return response()->json(['users' => array_map(fn (object $u): array => [
            'id' => (int) $u->id, 'email' => $u->email, 'name' => $u->name, 'role' => $u->role, 'active' => (bool) $u->active,
            'mfaEnrolled' => $u->totp_confirmed_at !== null, 'mustChangePassword' => (bool) $u->must_change_password,
            'lastLoginAt' => $u->last_login_at,
        ], $this->accounts->all())], 200, ['Cache-Control' => 'no-store']);
    }

    public function store(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $name = $request->input('name');
        $role = $request->input('role');
        if (! is_string($email) || ! is_string($name) || ! is_string($role)) {
            return ApiError::response('validationFailed', 'Indica correo, nombre y rol.', 422);
        }
        try {
            $created = $this->accounts->create($email, $name, $role, $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            return ApiError::response('validationFailed', $e->getMessage(), 422);
        }

        return response()->json(['id' => $created['id'], 'tempPassword' => $created['tempPassword']], 201, ['Cache-Control' => 'no-store']);
    }

    public function update(Request $request, int $id): JsonResponse|Response
    {
        $changes = [];
        foreach (['role', 'name'] as $field) {
            if ($request->has($field)) {
                if (! is_string($request->input($field))) {
                    return ApiError::response('validationFailed', "«{$field}» debe ser texto.", 422);
                }
                $changes[$field] = $request->input($field);
            }
        }
        if ($request->has('active')) {
            if (! is_bool($request->input('active'))) {
                return ApiError::response('validationFailed', '«active» debe ser verdadero o falso.', 422);
            }
            $changes['active'] = $request->input('active');
        }

        try {
            $this->accounts->update($id, $changes, $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            return ApiError::response('validationFailed', $e->getMessage(), 422);
        } catch (QueryException $e) {
            // La base impide quedarse sin superadmin: se traduce a un error claro.
            if (str_contains($e->getMessage(), 'al menos un superadmin')) {
                return ApiError::response('lastSuperadmin', 'Debe quedar al menos un superadmin activo.', 409);
            }
            throw $e;
        }

        return response()->noContent();
    }

    public function resetMfa(Request $request, int $id): JsonResponse|Response
    {
        try {
            $this->accounts->resetMfa($id, $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            return ApiError::response('notFound', $e->getMessage(), 404);
        }

        return response()->noContent();
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        try {
            $temp = $this->accounts->resetPassword($id, $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            return ApiError::response('notFound', $e->getMessage(), 404);
        }

        return response()->json(['tempPassword' => $temp], 200, ['Cache-Control' => 'no-store']);
    }

    private function actor(Request $request): string
    {
        return 'admin:'.$request->attributes->get('admin_user')->id;
    }
}
