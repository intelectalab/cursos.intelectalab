<?php

use App\Modules\Cms\Http\Controllers\PageController;
use App\Modules\Cms\Http\Controllers\PublicPageController;
use Illuminate\Support\Facades\Route;

// Sitio público de la organización: sin sesión
Route::middleware(['tenant', 'throttle:120,1'])->prefix('site')->group(function () {
    Route::get('/pages', [PublicPageController::class, 'index']);
    Route::get('/pages/{slug}', [PublicPageController::class, 'show'])->where('slug', '[a-z0-9-]{1,80}');
});

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->prefix('pages')->group(function () {
    Route::middleware('permission:cms.read,cms.update,cms.publish')->group(function () {
        Route::get('/', [PageController::class, 'index']);
        Route::get('/{page}', [PageController::class, 'show'])->whereUuid('page');
    });

    // Editar: solo el borrador
    Route::middleware('permission:cms.update')->group(function () {
        Route::post('/', [PageController::class, 'store']);
        Route::post('/media', [PageController::class, 'uploadMedia']);
        Route::put('/{page}', [PageController::class, 'update'])->whereUuid('page');
        Route::delete('/{page}', [PageController::class, 'destroy'])->whereUuid('page');
        // Paso de revisión: quien redacta pide que publiquen su borrador
        Route::post('/{page}/submit-review', [PageController::class, 'submitForReview'])->whereUuid('page');
    });

    // Publicar es un permiso aparte (RN-017)
    Route::middleware('permission:cms.publish')->group(function () {
        // Maestro §7.2
        Route::post('/{page}/publish', [PageController::class, 'publish'])->whereUuid('page');
        Route::post('/{page}/unpublish', [PageController::class, 'unpublish'])->whereUuid('page');
        // Devolver el borrador con observaciones en vez de publicarlo
        Route::post('/{page}/request-changes', [PageController::class, 'requestChanges'])->whereUuid('page');
    });
});
