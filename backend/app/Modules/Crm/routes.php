<?php

use App\Modules\Crm\Http\Controllers\CompanyController;
use App\Modules\Crm\Http\Controllers\LeadController;
use App\Modules\Crm\Http\Controllers\LeadImportController;
use App\Modules\Crm\Http\Controllers\OpportunityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->prefix('crm')->group(function () {
    Route::middleware('permission:crm.read')->group(function () {
        Route::get('/owners', [LeadController::class, 'owners']);

        Route::get('/leads', [LeadController::class, 'index']);
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->whereUuid('lead');

        Route::get('/opportunities/board', [OpportunityController::class, 'board']);
        Route::get('/opportunities/{opportunity}', [OpportunityController::class, 'show'])->whereUuid('opportunity');
    });

    // Quien cotiza o lleva las cuentas corporativas necesita ver las empresas
    Route::middleware('permission:crm.read,quotations.read,corporate_accounts.read')->group(function () {
        Route::get('/companies', [CompanyController::class, 'index']);
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->whereUuid('company');
    });

    Route::middleware('permission:crm.manage')->group(function () {
        Route::post('/leads', [LeadController::class, 'store']);
        // Importar prospectos desde una hoja
        Route::get('/leads/import/template', [LeadImportController::class, 'template']);
        Route::post('/leads/import/preview', [LeadImportController::class, 'preview']);
        Route::post('/leads/import', [LeadImportController::class, 'store']);
        Route::put('/leads/{lead}', [LeadController::class, 'update'])->whereUuid('lead');
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->whereUuid('lead');
        Route::post('/leads/{lead}/discard', [LeadController::class, 'discard'])->whereUuid('lead');

        Route::post('/companies', [CompanyController::class, 'store']);
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->whereUuid('company');
        Route::post('/companies/{company}/contacts', [CompanyController::class, 'storeContact'])->whereUuid('company');
        Route::put('/contacts/{contact}', [CompanyController::class, 'updateContact'])->whereUuid('contact');
        Route::delete('/contacts/{contact}', [CompanyController::class, 'destroyContact'])->whereUuid('contact');

        Route::post('/opportunities', [OpportunityController::class, 'store']);
        Route::put('/opportunities/{opportunity}', [OpportunityController::class, 'update'])->whereUuid('opportunity');
        Route::post('/opportunities/{opportunity}/stage', [OpportunityController::class, 'move'])->whereUuid('opportunity');
        Route::post('/opportunities/{opportunity}/activities', [OpportunityController::class, 'storeActivity'])->whereUuid('opportunity');
    });
});
