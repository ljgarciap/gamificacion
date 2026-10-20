<?php

namespace App\Http;

use App\Domain\Redemption\RedeemFailure;
use Illuminate\Http\JsonResponse;

/**
 * Forma única de los errores de la API: {"error": {"code": ..., "message": ..., "retryAfter"?: segundos}}.
 * `code` es estable y lo mapea el cliente (coincide con FailureReason de Flutter); `message` es solo orientativo.
 * Nunca incluye detalles internos ni diferencias que sirvan para sondear el sistema.
 */
final class ApiError
{
    public static function response(string $code, string $message, int $status, ?int $retryAfter = null, array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message] + ($retryAfter !== null ? ['retryAfter' => $retryAfter] : []);
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response()->json(['error' => $error], $status, $headers + ['Cache-Control' => 'no-store']);
    }

    public static function fromFailure(RedeemFailure $e): JsonResponse
    {
        return self::response($e->reason, $e->userMessage(), $e->httpStatus(), $e->retryAfter);
    }
}
