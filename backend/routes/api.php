<?php

use App\Http\Controllers\BalanceController;
use App\Http\Controllers\RedeemController;
use Illuminate\Support\Facades\Route;

// Prefijo /v1 (ver bootstrap/app.php). Todo requiere el JWT de la app host.
Route::middleware('host.auth')->group(function (): void {
    Route::post('/codes/redeem', RedeemController::class)->middleware('throttle:redeem');
    Route::get('/balance', BalanceController::class);
});
