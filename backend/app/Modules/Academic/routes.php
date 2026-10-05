<?php

use App\Modules\Academic\Http\Controllers\ActivityController;
use App\Modules\Academic\Http\Controllers\QuestionBankController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Bancos de preguntas de la organización
    Route::middleware('permission:question_banks.read')->group(function () {
        Route::get('/question-banks', [QuestionBankController::class, 'index']);
        Route::get('/question-banks/{bank}/questions', [QuestionBankController::class, 'questions'])->whereUuid('bank');
    });

    Route::middleware('permission:question_banks.manage')->group(function () {
        Route::post('/question-banks', [QuestionBankController::class, 'store']);
        Route::put('/question-banks/{bank}', [QuestionBankController::class, 'update'])->whereUuid('bank');
        Route::delete('/question-banks/{bank}', [QuestionBankController::class, 'destroy'])->whereUuid('bank');
        Route::post('/question-banks/{bank}/questions', [QuestionBankController::class, 'storeQuestion'])->whereUuid('bank');
        Route::put('/questions/{question}', [QuestionBankController::class, 'updateQuestion'])->whereUuid('question');
        Route::delete('/questions/{question}', [QuestionBankController::class, 'destroyQuestion'])->whereUuid('question');
    });

    // Actividades de un curso: mismas reglas de alcance que su contenido
    Route::get('/courses/{course}/activities', [ActivityController::class, 'index'])
        ->whereUuid('course')
        ->middleware('permission:courses.read,courses.assigned.read');

    Route::middleware('permission:courses.content.write,activities.assigned.manage')
        ->prefix('/courses/{course}')
        ->whereUuid(['course', 'section', 'activity'])
        ->group(function () {
            Route::post('/sections/{section}/activities', [ActivityController::class, 'store']);
            Route::put('/sections/{section}/activities/order', [ActivityController::class, 'reorder']);
            Route::put('/activities/{activity}', [ActivityController::class, 'update']);
            Route::delete('/activities/{activity}', [ActivityController::class, 'destroy']);
            Route::put('/activities/{activity}/questions', [ActivityController::class, 'setQuestions']);
        });
});
