<?php

use App\Modules\Billing\Http\Controllers\PlatformPlanController;
use App\Modules\Billing\Http\Controllers\PlatformPlanOrderController;
use App\Modules\Billing\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

// Plataforma: catálogo de planes y suscripción de cada organización
Route::prefix('platform')->middleware(['central', 'auth:sanctum', 'platform.admin'])->group(function () {
    Route::get('/plans', [PlatformPlanController::class, 'index']);
    Route::post('/plans', [PlatformPlanController::class, 'store']);
    Route::put('/plans/{plan}', [PlatformPlanController::class, 'update'])->whereUuid('plan');
    Route::put('/tenants/{tenant}/subscription', [PlatformPlanController::class, 'assign'])->whereUuid('tenant');
    Route::put('/tenants/{tenant}/subscription/trial', [PlatformPlanController::class, 'extendTrial'])->whereUuid('tenant');

    // Pagos de plan de las organizaciones y cómo pagarle a la plataforma
    Route::get('/plan-orders', [PlatformPlanOrderController::class, 'index']);
    Route::post('/plan-orders/{order}/confirm', [PlatformPlanOrderController::class, 'confirm'])->whereUuid('order');
    Route::post('/plan-orders/{order}/cancel', [PlatformPlanOrderController::class, 'cancel'])->whereUuid('order');
    Route::get('/billing-settings', [PlatformPlanOrderController::class, 'settings']);
    Route::put('/billing-settings', [PlatformPlanOrderController::class, 'updateSettings']);
});

// Organización: su propio plan (lo consulta quien ve la configuración)
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:tenant_settings.read'])->group(function () {
    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
});

// Organización: pedir un plan y dar seguimiento a su pago (quien puede cambiar la configuración)
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:tenant_settings.update'])->whereUuid('order')->group(function () {
    Route::post('/subscription/orders', [SubscriptionController::class, 'storeOrder']);
    Route::put('/subscription/orders/{order}/payment', [SubscriptionController::class, 'informPayment']);
    Route::post('/subscription/orders/{order}/cancel', [SubscriptionController::class, 'cancelOrder']);
});
