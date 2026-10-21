<?php

use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\SettingsController;
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

Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::SETTINGS_MANAGE])->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::get('/settings/{key}/history', [SettingsController::class, 'history'])->where('key', '[a-z0-9_.]+');
    Route::put('/settings/{key}', [SettingsController::class, 'update'])->where('key', '[a-z0-9_.]+');
});

// Control dual: ver (todos los roles), solicitar y ejecutar (quien puede pedir), aprobar (quien puede aprobar). El servicio exige además el
// permiso propio de cada acción y que aprobador ≠ solicitante.
Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::APPROVALS_VIEW])->get('/approvals', [ApprovalController::class, 'index']);
Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::APPROVALS_REQUEST])->group(function (): void {
    Route::post('/approvals', [ApprovalController::class, 'store']);
    Route::post('/approvals/{id}/execute', [ApprovalController::class, 'execute'])->whereNumber('id');
    Route::get('/approvals/{id}/download', [ApprovalController::class, 'download'])->whereNumber('id');
});
Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::APPROVALS_APPROVE])->post('/approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id');

// Campañas y premios (RF-35): leer todos los roles; escribir superadmin y operador. Una campaña con un lote comprometido queda congelada por la base.
Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::CAMPAIGNS_VIEW])->group(function (): void {
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::get('/campaigns/{id}', [CampaignController::class, 'show']);
});
Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::CAMPAIGNS_MANAGE])->group(function (): void {
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::patch('/campaigns/{id}', [CampaignController::class, 'update']);
    Route::post('/campaigns/{id}/prizes', [CampaignController::class, 'addPrize']);
    Route::patch('/campaigns/{id}/prizes/{prizeId}', [CampaignController::class, 'updatePrize']);
    Route::delete('/campaigns/{id}/prizes/{prizeId}', [CampaignController::class, 'deletePrize']);
});

// Lotes (RF-36/37): leer y buscar códigos, todos los roles; crear, comprometer, cerrar y revelar, superadmin y operador. Activar es control dual.
Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::BATCHES_VIEW])->group(function (): void {
    Route::get('/batches', [BatchController::class, 'index']);
    Route::get('/batches/{id}', [BatchController::class, 'show']);
    Route::get('/batches/{id}/redemptions', [BatchController::class, 'redemptions']);
    Route::post('/codes/lookup', [BatchController::class, 'lookup']);
});
Route::middleware(['admin.auth', 'admin.can:'.AdminPermissions::BATCHES_MANAGE])->group(function (): void {
    Route::post('/batches', [BatchController::class, 'store']);
    Route::post('/batches/{id}/commit', [BatchController::class, 'commit']);
    Route::post('/batches/{id}/close', [BatchController::class, 'close']);
    Route::post('/batches/{id}/reveal', [BatchController::class, 'reveal']);
});
