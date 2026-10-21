<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\UserController;
use App\Domain\Admin\AdminPermissions;
use Illuminate\Support\Facades\Route;

// Panel de administración (SPEC-003). Prefijo /admin/api/v1, sesión + CSRF (ver bootstrap/app.php). NADA de esto usa el JWT de los hosts.
// Regla: toda ruta, salvo las de inicio de sesión de abajo, pasa por `admin.auth` y DECLARA el permiso que exige con `admin.can:`.

// Inicio de sesión: rutas "pendientes" (sin sesión completa). La lista está fijada también en la prueba de arquitectura de rutas.
Route::get('/auth/csrf', [AuthController::class, 'csrf']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/password', [AuthController::class, 'password']);
Route::post('/auth/mfa/enroll', [AuthController::class, 'enrollStart']);
Route::post('/auth/mfa/enroll/confirm', [AuthController::class, 'enrollConfirm']);
Route::post('/auth/mfa', [AuthController::class, 'mfa']);

Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::ACCOUNT_SELF])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::USERS_MANAGE])->group(function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{id}', [UserController::class, 'update'])->whereNumber('id');
    Route::post('/users/{id}/reset-mfa', [UserController::class, 'resetMfa'])->whereNumber('id');
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('id');
});
