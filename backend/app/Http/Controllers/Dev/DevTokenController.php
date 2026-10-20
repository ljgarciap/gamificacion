<?php

namespace App\Http\Controllers\Dev;

use App\Http\ApiError;
use Firebase\JWT\JWT;
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
    public const LIFETIME = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $subject = $request->input('userId', 'demo-user');
        if (! is_string($subject) || preg_match('/^[\x21-\x7E]{1,128}$/', $subject) !== 1) {
            return ApiError::response('invalidUserId', 'userId: de 1 a 128 caracteres ASCII imprimibles, sin espacios.', 422);
        }

        $issuer = (string) config('gamification.dev.issuer');
        $host = config("gamification.hosts.$issuer");
        $key = is_array($host) && ($host['alg'] ?? null) === 'HS256' && is_string($host['key'] ?? null) ? base64_decode($host['key'], true) : false;
        if ($key === false || $key === '') {
            return ApiError::response('devIssuerNotConfigured', "El emisor de desarrollo «{$issuer}» no está en GAMIF_HOSTS con alg HS256.", 503);
        }

        $now = time();
        $token = JWT::encode([
            'iss' => $issuer, 'sub' => $subject, 'aud' => (string) config('gamification.token.audience'),
            'iat' => $now, 'exp' => $now + self::LIFETIME,
        ], $key, 'HS256');

        return response()->json(
            ['token' => $token, 'expiresIn' => self::LIFETIME, 'userId' => $issuer.':'.$subject],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
