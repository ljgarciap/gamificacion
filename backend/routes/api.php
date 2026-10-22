<?php

use App\Http\Controllers\BalanceController;
use App\Http\Controllers\Dev\DevDemoCodesController;
use App\Http\Controllers\Dev\DevTokenController;
use App\Http\Controllers\RedeemController;
use App\Http\Controllers\SpinController;
use Illuminate\Support\Facades\Route;

// Prefijo /v1 (ver bootstrap/app.php). Todo requiere el JWT de la app host.
Route::middleware('host.auth')->group(function (): void {
    Route::post('/codes/redeem', RedeemController::class)->middleware('throttle:redeem');
    Route::post('/spins', SpinController::class)->middleware('throttle:redeem');
    Route::get('/balance', BalanceController::class);
});

// Utilidades SOLO de desarrollo (sin JWT: es justamente lo que emiten). Doble condición: variable activada Y entorno local/testing.
// En producción estas rutas NO EXISTEN, aunque alguien active la variable por error.
if (config('gamification.dev.enabled') && app()->environment('local', 'testing')) {
    Route::post('/dev/token', DevTokenController::class)->middleware('throttle:60,1');
    Route::get('/dev/demo-codes', DevDemoCodesController::class);
}

// MODO DEMO: lo mismo que lo anterior pero apto para un servidor de demostración (cualquier entorno). Requiere activarlo A PROPÓSITO con
// GAMIF_DEMO_MODE=true; apagado, las rutas no existen. Con límite de peticiones por IP. El emisor es `gamification.dev.issuer` (debe existir
// en GAMIF_HOSTS). Las rutas /v1/dev/* siguen existiendo solo en local/testing.
if (config('gamification.demo.enabled')) {
    Route::post('/demo/token', DevTokenController::class)->middleware('throttle:30,1');
    Route::get('/demo/codes', DevDemoCodesController::class)->middleware('throttle:60,1');
}
