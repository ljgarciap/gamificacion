<?php

use App\Http\Controllers\BalanceController;
use App\Http\Controllers\Demo\DemoPublicController;
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

// MUESTRA REAL (SPEC-006): las rutas existen siempre; las enciende y apaga el superadmin desde el panel (`demo_config`). Apagada, `demo.active`
// responde 404. `status` es la excepción: siempre 200, para que la app sepa si ofrecer el recorrido de la muestra.
Route::get('/demo/status', [DemoPublicController::class, 'status'])->middleware('throttle:60,1');
Route::middleware(['demo.active', 'throttle:20,1'])->post('/demo/session', [DemoPublicController::class, 'session']);
Route::middleware(['demo.active', 'host.auth', 'demo.session', 'throttle:20,1'])->post('/demo/purchase', [DemoPublicController::class, 'purchase']);
