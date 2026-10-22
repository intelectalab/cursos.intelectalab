<?php

use App\Modules\Assistant\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

// Asistente: solo lectura; cada sección de la respuesta exige además el permiso de su módulo
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:assistant.use', 'throttle:60,1'])->prefix('/assistant')->group(function () {
    Route::get('/insights', [AssistantController::class, 'insights']);
    Route::get('/catalog', [AssistantController::class, 'catalog']);
});
