<?php

use App\Modules\Platform\Http\Controllers\PlatformAuthController;
use App\Modules\Platform\Http\Controllers\PlatformBackupController;
use App\Modules\Platform\Http\Controllers\PlatformDashboardController;
use App\Modules\Platform\Http\Controllers\PlatformMailController;
use App\Modules\Platform\Http\Controllers\PlatformTenantController;
use Illuminate\Support\Facades\Route;

// Operaciones globales de la plataforma: solo en el dominio central
Route::prefix('platform')->middleware('central')->group(function () {
    Route::post('/auth/login', [PlatformAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'platform.admin'])->group(function () {
        Route::get('/auth/me', [PlatformAuthController::class, 'me']);
        Route::post('/auth/logout', [PlatformAuthController::class, 'logout']);

        // Panel: una sección por pestaña y su descarga en Excel
        Route::get('/dashboard/export', [PlatformDashboardController::class, 'export']);
        Route::get('/dashboard/{section}', [PlatformDashboardController::class, 'show'])->where('section', '[a-z]+');

        // Respaldos semanales para descargar
        Route::get('/backups', [PlatformBackupController::class, 'index']);
        Route::post('/backups', [PlatformBackupController::class, 'store'])->middleware('throttle:5,1');
        Route::get('/backups/{name}', [PlatformBackupController::class, 'download'])->where('name', 'erp-(semana|manual)-[0-9-]+\.tar\.gz')->middleware('throttle:10,1');

        // Correo de envío de la plataforma
        Route::get('/mail', [PlatformMailController::class, 'show']);
        Route::put('/mail', [PlatformMailController::class, 'update']);
        Route::post('/mail/test', [PlatformMailController::class, 'test'])->middleware('throttle:10,1');

        Route::get('/tenants', [PlatformTenantController::class, 'index']);
        Route::post('/tenants', [PlatformTenantController::class, 'store']);
        Route::get('/tenants/{tenant}', [PlatformTenantController::class, 'show'])->whereUuid('tenant');
        Route::put('/tenants/{tenant}', [PlatformTenantController::class, 'update'])->whereUuid('tenant');
        Route::post('/tenants/{tenant}/admins/{user}/reset-password', [PlatformTenantController::class, 'resetAdminPassword'])->whereUuid(['tenant', 'user']);
        Route::post('/tenants/{tenant}/support-session', [PlatformTenantController::class, 'supportSession']);
        Route::patch('/tenants/{tenant}/status', [PlatformTenantController::class, 'updateStatus']);
    });
});
