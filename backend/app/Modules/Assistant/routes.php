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

    // Memoria del buscador: la única escritura del asistente
    Route::get('/search-aliases', [AssistantController::class, 'aliases']);
    Route::post('/search-feedback', [AssistantController::class, 'feedback'])->middleware('throttle:30,1');
    Route::get('/companions', [AssistantController::class, 'companions']);

    // Revisar y borrar sinónimos equivocados: quien edita la configuración
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::get('/search-aliases/learned', [AssistantController::class, 'aliasList']);
        Route::delete('/search-aliases/{alias}', [AssistantController::class, 'forgetAlias'])->whereUuid('alias');
    });
});

// Ficha pública del curso: «Quienes llevaron este curso también tomaron…», sin iniciar sesión
Route::middleware(['tenant', 'throttle:120,1'])->get('/catalog/courses/{course}/companions', [AssistantController::class, 'publicCompanions'])->whereUuid('course');
