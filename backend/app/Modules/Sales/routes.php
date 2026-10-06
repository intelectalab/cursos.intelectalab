<?php

use App\Modules\Sales\Http\Controllers\OrderController;
use App\Modules\Sales\Http\Controllers\PackageController;
use Illuminate\Support\Facades\Route;

// Catálogo público: paquetes en venta, sin iniciar sesión
Route::middleware(['tenant', 'throttle:120,1'])->group(function () {
    Route::get('/catalog/packages', [PackageController::class, 'catalog']);
    Route::get('/catalog/packages/{package}', [PackageController::class, 'catalogShow'])->whereUuid('package');
});

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
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
