<?php

use App\Modules\Platform\Http\Controllers\PlatformAuthController;
use App\Modules\Platform\Http\Controllers\PlatformTenantController;
use Illuminate\Support\Facades\Route;

// Operaciones globales de la plataforma: solo en el dominio central
Route::prefix('platform')->middleware('central')->group(function () {
    Route::post('/auth/login', [PlatformAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'platform.admin'])->group(function () {
        Route::get('/auth/me', [PlatformAuthController::class, 'me']);
        Route::post('/auth/logout', [PlatformAuthController::class, 'logout']);

        Route::get('/tenants', [PlatformTenantController::class, 'index']);
        Route::post('/tenants', [PlatformTenantController::class, 'store']);
        Route::post('/tenants/{tenant}/support-session', [PlatformTenantController::class, 'supportSession']);
        Route::patch('/tenants/{tenant}/status', [PlatformTenantController::class, 'updateStatus']);
    });
});
