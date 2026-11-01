<?php

use App\Modules\Invoicing\Http\Controllers\InvoiceController;
use App\Modules\Invoicing\Http\Controllers\InvoicingSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Si la academia emite comprobantes: el checkout pregunta entonces boleta o factura
    Route::get('/invoicing/status', [InvoicingSettingsController::class, 'status']);

    // Datos de emisión y cuenta de Mifact de la academia
    Route::get('/settings/invoicing', [InvoicingSettingsController::class, 'show'])->middleware('permission:tenant_settings.read');
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::put('/settings/invoicing', [InvoicingSettingsController::class, 'update'])->middleware('throttle:10,1');
        Route::post('/settings/invoicing/check', [InvoicingSettingsController::class, 'check'])->middleware('throttle:10,1');
    });

    // Comprobantes de una orden: los ve quien consulta las órdenes
    Route::middleware('permission:orders.read,orders.support.read')->group(function () {
        Route::get('/orders/{order}/invoices', [InvoiceController::class, 'index'])->whereUuid('order');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereUuid('invoice')->middleware('throttle:30,1');
    });
    // Los corrige y reintenta quien confirma los pagos
    Route::middleware('permission:payments.confirm')->group(function () {
        Route::put('/orders/{order}/billing', [InvoiceController::class, 'updateBilling'])->whereUuid('order');
        Route::post('/invoices/{invoice}/retry', [InvoiceController::class, 'retry'])->whereUuid('invoice')->middleware('throttle:20,1');
    });

    // El comprador: los comprobantes de sus compras
    Route::middleware('permission:orders.create')->group(function () {
        Route::get('/me/orders/{order}/invoices', [InvoiceController::class, 'mine'])->whereUuid('order');
        Route::get('/me/invoices/{invoice}/pdf', [InvoiceController::class, 'minePdf'])->whereUuid('invoice')->middleware('throttle:30,1');
    });
});
