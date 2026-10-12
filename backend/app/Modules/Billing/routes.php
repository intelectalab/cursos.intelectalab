<?php

use App\Modules\Billing\Http\Controllers\PlatformGatewayController;
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
    Route::post('/plan-orders/{order}/revert', [PlatformPlanOrderController::class, 'revert'])->whereUuid('order');
    Route::post('/plan-orders/{order}/refund', [PlatformPlanOrderController::class, 'refund'])->whereUuid('order');
    Route::get('/plan-orders/{order}/proof', [PlatformPlanOrderController::class, 'proof'])->whereUuid('order');
    Route::get('/plan-orders/summary', [PlatformPlanOrderController::class, 'summary']);
    Route::get('/billing-settings', [PlatformPlanOrderController::class, 'settings']);
    Route::put('/billing-settings', [PlatformPlanOrderController::class, 'updateSettings']);

    // Cuentas de la plataforma en las pasarelas: con ellas cobra los planes en línea
    Route::get('/payment-gateways', [PlatformGatewayController::class, 'index']);
    Route::get('/payment-gateways/errors', [PlatformGatewayController::class, 'errors']);
    Route::put('/payment-gateways/{gateway}', [PlatformGatewayController::class, 'update']);
    Route::delete('/payment-gateways/{gateway}', [PlatformGatewayController::class, 'destroy']);
});

// Avisos de las pasarelas a la plataforma: llegan sin sesión; el pago se comprueba en la propia pasarela
Route::post('/platform/payments/webhooks/{gateway}', [PlatformGatewayController::class, 'webhook'])->middleware(['central', 'throttle:120,1']);

// Organización: su propio plan (lo consulta quien ve la configuración)
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:tenant_settings.read'])->group(function () {
    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
    Route::get('/subscription/orders/{order}/proof', [SubscriptionController::class, 'downloadProof'])->whereUuid('order');
});

// Organización: pedir un plan y dar seguimiento a su pago (quien puede cambiar la configuración)
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:tenant_settings.update'])->whereUuid('order')->group(function () {
    Route::post('/subscription/orders', [SubscriptionController::class, 'storeOrder']);
    Route::put('/subscription/orders/{order}/payment', [SubscriptionController::class, 'informPayment']);
    Route::post('/subscription/orders/{order}/cancel', [SubscriptionController::class, 'cancelOrder']);
    // Constancia del pago hecho por fuera
    Route::post('/subscription/orders/{order}/proof', [SubscriptionController::class, 'uploadProof']);
    Route::delete('/subscription/orders/{order}/proof', [SubscriptionController::class, 'removeProof']);
    // Pago en línea: ir a la pasarela y, al volver, preguntarle si se pagó
    Route::post('/subscription/orders/{order}/pay', [SubscriptionController::class, 'pay'])->middleware('throttle:20,1');
    Route::post('/subscription/orders/{order}/pay/sync', [SubscriptionController::class, 'syncPayment'])->middleware('throttle:30,1');
    // Ya vio cómo se resolvió su orden
    Route::post('/subscription/notice/seen', [SubscriptionController::class, 'markSeen']);
});
