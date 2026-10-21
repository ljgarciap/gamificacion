<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Parámetros administrables (solo superadmin). Los de seguridad exigirán control dual (P3); mientras no exista, no se cambian por aquí. */
final class SettingsController
{
    public function __construct(private readonly Settings $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['settings' => $this->settings->describe()], 200, ['Cache-Control' => 'no-store']);
    }

    public function history(string $key): JsonResponse
    {
        if (SettingsRegistry::get($key) === null) {
            return ApiError::response('notFound', 'Parámetro desconocido.', 404);
        }

        return response()->json(['history' => $this->settings->history($key)], 200, ['Cache-Control' => 'no-store']);
    }

    public function update(Request $request, string $key): JsonResponse|Response
    {
        $def = SettingsRegistry::get($key);
        if ($def === null) {
            return ApiError::response('notFound', 'Parámetro desconocido.', 404);
        }
        if ($def['security']) {
            return ApiError::response('approvalRequired', 'Este parámetro es de seguridad y exige aprobación de un segundo usuario.', 409);
        }
        try {
            $this->settings->set($key, $request->input('value'), 'admin:'.$request->attributes->get('admin_user')->id);
        } catch (\InvalidArgumentException $e) {
            return ApiError::response('validationFailed', $e->getMessage(), 422);
        }

        return response()->noContent();
    }
}
