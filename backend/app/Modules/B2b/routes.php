<?php

use App\Modules\B2b\Http\Controllers\CorporateAccountController;
use App\Modules\B2b\Http\Controllers\QuotationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Cotizaciones corporativas
    Route::middleware('permission:quotations.read')->group(function () {
        Route::get('/quotations', [QuotationController::class, 'index']);
        Route::get('/quotations/options', [QuotationController::class, 'options']);
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->whereUuid('quotation');
    });

    Route::middleware('permission:quotations.create')->group(function () {
        // Maestro §7.2
        Route::post('/quotations', [QuotationController::class, 'store']);
        Route::put('/quotations/{quotation}', [QuotationController::class, 'update'])->whereUuid('quotation');
        Route::post('/quotations/{quotation}/send', [QuotationController::class, 'send'])->whereUuid('quotation');
        Route::post('/quotations/{quotation}/revise', [QuotationController::class, 'revise'])->whereUuid('quotation');
    });

    Route::middleware('permission:quotations.accept')->group(function () {
        Route::post('/quotations/{quotation}/accept', [QuotationController::class, 'accept'])->whereUuid('quotation');
        Route::post('/quotations/{quotation}/reject', [QuotationController::class, 'reject'])->whereUuid('quotation');
    });

    // Portal del Responsable de empresa: todo acotado a la empresa a la que está vinculado
    Route::middleware('permission:company.portal')->prefix('company')->group(function () {
        Route::get('/', [CorporateAccountController::class, 'show']);
        Route::post('/members', [CorporateAccountController::class, 'storeMember']);
        Route::post('/courses/{course}/assign', [CorporateAccountController::class, 'assign'])->whereUuid('course');
        Route::post('/assignments/{assignment}/revoke', [CorporateAccountController::class, 'revoke'])->whereUuid('assignment');
        Route::get('/quotations', [CorporateAccountController::class, 'quotations']);
        Route::get('/quotations/{quotation}', [CorporateAccountController::class, 'quotation'])->whereUuid('quotation');
        Route::post('/quotations/{quotation}/accept', [CorporateAccountController::class, 'acceptQuotation'])->whereUuid('quotation');
        Route::post('/quotations/{quotation}/reject', [CorporateAccountController::class, 'rejectQuotation'])->whereUuid('quotation');
    });

    // Personal de la academia: la misma cuenta, eligiendo la empresa
    Route::prefix('corporate-accounts/{company}')->whereUuid('company')->group(function () {
        Route::get('/', [CorporateAccountController::class, 'show'])->middleware('permission:corporate_accounts.read,licenses.read');
        Route::post('/members', [CorporateAccountController::class, 'storeMember'])->middleware('permission:corporate_accounts.manage');

        Route::middleware('permission:licenses.assign')->group(function () {
            Route::post('/courses/{course}/assign', [CorporateAccountController::class, 'assign'])->whereUuid('course');
            Route::post('/assignments/{assignment}/revoke', [CorporateAccountController::class, 'revoke'])->whereUuid('assignment');
        });
    });

    // Maestro §7.2
    Route::post('/licenses/{license}/assign', [CorporateAccountController::class, 'assignLicense'])
        ->whereUuid('license')->middleware('permission:licenses.assign');

    // Entregar las licencias de una venta a empresa antes de que pague
    Route::post('/orders/{order}/release-licenses', [CorporateAccountController::class, 'releaseUnpaid'])
        ->whereUuid('order')->middleware('permission:payments.defer');
});
