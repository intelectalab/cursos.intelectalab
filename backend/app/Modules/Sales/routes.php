<?php

use App\Modules\Sales\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Comprador: crea la orden (Maestro §7.2) y consulta o cancela las suyas
    Route::middleware('permission:orders.create')->group(function () {
        Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/me/orders', [OrderController::class, 'mine']);
        Route::get('/me/orders/{order}', [OrderController::class, 'showMine'])->whereUuid('order');
        Route::post('/me/orders/{order}/cancel', [OrderController::class, 'cancelMine'])->whereUuid('order');
    });

    // Personal: todas las órdenes de la organización. Soporte las consulta en solo lectura (Maestro §8.1).
    Route::middleware('permission:orders.read,orders.support.read')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/options', [OrderController::class, 'options']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereUuid('order');
    });

    // Confirmar un pago recibido fuera del sistema. POST /orders/{id}/payments queda para la pasarela (S6).
    Route::post('/orders/{order}/confirm-payment', [OrderController::class, 'confirmPayment'])
        ->whereUuid('order')->middleware('permission:payments.confirm');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->whereUuid('order')->middleware('permission:orders.cancel');
});
