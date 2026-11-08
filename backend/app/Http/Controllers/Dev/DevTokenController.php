<?php

namespace App\Http\Controllers\Dev;

use App\Http\ApiError;
use App\Domain\Dev\DevIssuerNotConfigured;
use App\Domain\Dev\DevTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/dev/token {"userId": "demo-user"} — SOLO desarrollo local (las rutas no existen en otros entornos, ver routes/api.php).
 *
 * Hace de "app host de mentira": firma un JWT con el emisor de desarrollo para que la app independiente pueda autenticarse sin una app host.
 * Pasa por el mismo verificador que cualquier host real; no hay atajos en la validación.
 */
final class DevTokenController
{
    public const LIFETIME = DevTokenIssuer::LIFETIME;

    public function __invoke(Request $request): JsonResponse
    {
        $subject = $request->input('userId', 'demo-user');
        if (! is_string($subject) || preg_match('/^[\x21-\x7E]{1,128}$/', $subject) !== 1) {
            return ApiError::response('invalidUserId', 'userId: de 1 a 128 caracteres ASCII imprimibles, sin espacios.', 422);
        }

        try {
            $issued = DevTokenIssuer::issue($subject, self::LIFETIME);
        } catch (DevIssuerNotConfigured $e) {
            return ApiError::response('devIssuerNotConfigured', $e->getMessage(), 503);
        }

        return response()->json(
            ['token' => $issued['token'], 'expiresIn' => $issued['expiresIn'], 'userId' => $issued['userId']],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
