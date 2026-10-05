<?php

use App\Modules\Tenancy\Http\Controllers\GradeScaleController;
use App\Modules\Tenancy\Http\Controllers\TenantController;
use App\Modules\Tenancy\Http\Controllers\TenantSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/tenant', [TenantController::class, 'current']);

// Alta de organizaciones: solo desde el dominio central
Route::post('/tenants', [TenantController::class, 'register'])
    ->middleware(['central', 'throttle:10,1']);

// Configuración general de la organización
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    Route::get('/settings', [TenantSettingsController::class, 'show'])->middleware('permission:tenant_settings.read');
    Route::put('/settings', [TenantSettingsController::class, 'update'])->middleware('permission:tenant_settings.update');
    Route::post('/settings/logo', [TenantSettingsController::class, 'uploadLogo'])->middleware('permission:tenant_settings.update');
    Route::delete('/settings/logo', [TenantSettingsController::class, 'removeLogo'])->middleware('permission:tenant_settings.update');

    // Escalas de notas guardadas por la organización
    Route::get('/settings/grade-scales', [GradeScaleController::class, 'index'])->middleware('permission:tenant_settings.read');
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::post('/settings/grade-scales', [GradeScaleController::class, 'store']);
        Route::put('/settings/grade-scales/{scale}', [GradeScaleController::class, 'update'])->whereUuid('scale');
        Route::delete('/settings/grade-scales/{scale}', [GradeScaleController::class, 'destroy'])->whereUuid('scale');
    });
});
