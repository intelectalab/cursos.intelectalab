<?php

use App\Modules\Assistant\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

// Asistente: solo lectura; cada sección de la respuesta exige además el permiso de su módulo
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:assistant.use', 'throttle:60,1'])->prefix('/assistant')->group(function () {
    Route::get('/insights', [AssistantController::class, 'insights']);
    Route::get('/catalog', [AssistantController::class, 'catalog']);

    // Fichas: cada una con el permiso de su módulo
    Route::get('/courses/{course}/context', [AssistantController::class, 'course'])->whereUuid('course')->middleware('permission:courses.read,courses.assigned.read');
    Route::middleware('permission:students.read')->group(function () {
        Route::get('/students', [AssistantController::class, 'students']);
        Route::get('/students/{student}/context', [AssistantController::class, 'student'])->whereUuid('student');
    });
    Route::get('/schedule', [AssistantController::class, 'schedule'])->middleware('permission:schedules.read');
    Route::get('/me', [AssistantController::class, 'mine'])->middleware('permission:enrollments.self.read');
});
