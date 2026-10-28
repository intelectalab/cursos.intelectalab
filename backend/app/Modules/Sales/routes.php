<?php

use App\Modules\Sales\Http\Controllers\AssistedSaleController;
use App\Modules\Sales\Http\Controllers\ExchangeRateController;
use App\Modules\Sales\Http\Controllers\OrderController;
use App\Modules\Sales\Http\Controllers\PackageController;
use App\Modules\Sales\Http\Controllers\PaymentGatewayController;
use App\Modules\Sales\Http\Controllers\RefundPolicyController;
use Illuminate\Support\Facades\Route;

// Catálogo público: paquetes en venta, sin iniciar sesión
Route::middleware(['tenant', 'throttle:120,1'])->group(function () {
    Route::get('/catalog/packages', [PackageController::class, 'catalog']);
    Route::get('/catalog/packages/{package}', [PackageController::class, 'catalogShow'])->whereUuid('package');
    // Política de reembolsos publicada por la academia (checkout y página pública)
    Route::get('/refund-policy', [RefundPolicyController::class, 'published']);
});

// Avisos de las pasarelas de pago: llegan sin sesión; el pago se comprueba en la propia pasarela
Route::post('/payments/webhooks/{gateway}', [PaymentGatewayController::class, 'webhook'])->middleware(['tenant', 'throttle:120,1']);

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Política de reembolsos: la ve quien consulta la configuración y la cambia quien la edita
    Route::get('/settings/refund-policy', [RefundPolicyController::class, 'show'])->middleware('permission:tenant_settings.read');
    Route::put('/settings/refund-policy', [RefundPolicyController::class, 'update'])->middleware('permission:tenant_settings.update');

    // Paquetes: gestión del personal
    Route::middleware('permission:packages.read')->group(function () {
        Route::get('/packages', [PackageController::class, 'index']);
        Route::get('/packages/{package}', [PackageController::class, 'show'])->whereUuid('package');
    });
    Route::middleware('permission:packages.manage')->group(function () {
        Route::post('/packages', [PackageController::class, 'store']);
        Route::put('/packages/{package}', [PackageController::class, 'update'])->whereUuid('package');
    });

    // Lo que ve el comprador antes de confirmar un paquete
    Route::get('/checkout/packages/{package}', [PackageController::class, 'checkout'])
        ->whereUuid('package')->middleware('permission:orders.create');

    // Comprador: crea la orden (Maestro §7.2) y consulta o cancela las suyas
    Route::middleware('permission:orders.create')->group(function () {
        Route::post('/orders', [OrderController::class, 'store'])->middleware(['throttle:20,1', 'idempotent']);
        Route::get('/me/orders', [OrderController::class, 'mine']);
        Route::get('/me/orders/{order}', [OrderController::class, 'showMine'])->whereUuid('order');
        Route::post('/me/orders/{order}/cancel', [OrderController::class, 'cancelMine'])->whereUuid('order');
        // Pago en línea: ir a la pasarela y, al volver, preguntarle si se pagó
        Route::post('/me/orders/{order}/pay', [OrderController::class, 'pay'])->whereUuid('order')->middleware('throttle:20,1');
        Route::post('/me/orders/{order}/pay/sync', [OrderController::class, 'syncPayment'])->whereUuid('order')->middleware('throttle:30,1');
    });

    // Cuentas de la organización en las pasarelas de pago
    Route::get('/settings/payment-gateways', [PaymentGatewayController::class, 'index'])->middleware('permission:tenant_settings.read');
    Route::get('/settings/payment-gateways/errors', [PaymentGatewayController::class, 'errors'])->middleware('permission:tenant_settings.read');
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::put('/settings/payment-gateways/{gateway}', [PaymentGatewayController::class, 'update']);
        Route::delete('/settings/payment-gateways/{gateway}', [PaymentGatewayController::class, 'destroy']);
    });

    // Tipo de cambio de la organización: cómo se obtiene, el vigente y su historial
    Route::get('/settings/exchange-rate', [ExchangeRateController::class, 'show'])->middleware('permission:tenant_settings.read');
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::put('/settings/exchange-rate', [ExchangeRateController::class, 'update']);
        Route::post('/settings/exchange-rate/manual', [ExchangeRateController::class, 'store']);
        Route::post('/settings/exchange-rate/refresh', [ExchangeRateController::class, 'refresh'])->middleware('throttle:10,1');
    });

    // Personal: todas las órdenes de la organización. Soporte las consulta en solo lectura (Maestro §8.1).
    // Venta asistida: el personal registra una compra a nombre de un alumno
    Route::middleware('permission:orders.assisted')->prefix('/orders/assisted')->group(function () {
        Route::get('/options', [AssistedSaleController::class, 'options']);
        Route::get('/students', [AssistedSaleController::class, 'students']);
        Route::post('/', [AssistedSaleController::class, 'store'])->middleware(['throttle:30,1', 'idempotent']);
    });

    Route::middleware('permission:orders.read,orders.support.read')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/options', [OrderController::class, 'options']);
        Route::get('/orders/export', [OrderController::class, 'export']);
        // Cuotas por cobrar y aviso de las vencidas
        Route::get('/orders/installments', [OrderController::class, 'installments']);
        Route::get('/orders/installments/alerts', [OrderController::class, 'installmentAlerts']);
        // Cobros de una pasarela que no se aplicaron a su orden
        Route::get('/orders/payment-reviews', [OrderController::class, 'paymentReviews']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereUuid('order');
    });

    // Confirmar un pago recibido fuera del sistema. POST /orders/{id}/payments queda para la pasarela (S6).
    Route::post('/orders/{order}/confirm-payment', [OrderController::class, 'confirmPayment'])
        ->whereUuid('order')->middleware(['permission:payments.confirm', 'idempotent']);
    // Plan de pagos: lo arma quien confirma los pagos, mientras la orden espera el primero
    Route::put('/orders/{order}/installments', [OrderController::class, 'setInstallments'])
        ->whereUuid('order')->middleware('permission:payments.confirm');
    Route::delete('/orders/{order}/installments', [OrderController::class, 'removeInstallments'])
        ->whereUuid('order')->middleware('permission:payments.confirm');
    // Volver a preguntar a la pasarela por una orden y dar por resuelto un cobro en revisión
    Route::post('/orders/{order}/recheck-payment', [OrderController::class, 'recheckPayment'])
        ->whereUuid('order')->middleware(['permission:payments.confirm', 'throttle:30,1']);
    Route::post('/orders/payment-reviews/{attempt}/resolve', [OrderController::class, 'resolvePaymentReview'])
        ->whereUuid('attempt')->middleware('permission:payments.confirm');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->whereUuid('order')->middleware('permission:orders.cancel');
    // Reembolso total de una compra pagada: devuelve el dinero y retira la matrícula
    Route::post('/orders/{order}/refund', [OrderController::class, 'refund'])
        ->whereUuid('order')->middleware(['permission:payments.refund', 'idempotent']);
});
