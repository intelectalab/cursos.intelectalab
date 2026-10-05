<?php

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
});
