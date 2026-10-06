<?php

use App\Modules\Claims\Http\Controllers\ClaimController;
use App\Modules\Claims\Http\Controllers\PublicClaimController;
use Illuminate\Support\Facades\Route;

// Libro de Reclamaciones virtual: público, sin sesión, con límite de envíos por dirección
Route::middleware('tenant')->prefix('claims')->group(function () {
    Route::get('/form', [PublicClaimController::class, 'form'])->middleware('throttle:60,1');
    // Maestro §7.2
    Route::post('/', [PublicClaimController::class, 'store'])->middleware('throttle:5,10');
    Route::post('/lookup', [PublicClaimController::class, 'lookup'])->middleware('throttle:10,1');
});

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->prefix('claims')->group(function () {
    Route::middleware('permission:claims.read')->group(function () {
        Route::get('/', [ClaimController::class, 'index']);
        Route::get('/{claim}', [ClaimController::class, 'show'])->whereUuid('claim');
    });

    Route::middleware('permission:claims.manage')->group(function () {
        Route::post('/{claim}/review', [ClaimController::class, 'startReview'])->whereUuid('claim');
        Route::post('/{claim}/respond', [ClaimController::class, 'respond'])->whereUuid('claim');
        Route::post('/{claim}/notes', [ClaimController::class, 'storeNote'])->whereUuid('claim');
    });
});
