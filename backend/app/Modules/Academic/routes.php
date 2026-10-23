<?php

use App\Modules\Academic\Http\Controllers\ActivityController;
use App\Modules\Academic\Http\Controllers\QuestionBankController;
use App\Modules\Academic\Http\Controllers\QuestionImportController;
use App\Modules\Academic\Http\Controllers\ReviewController;
use App\Modules\Academic\Http\Controllers\ScormContentController;
use App\Modules\Academic\Http\Controllers\ScormController;
use App\Modules\Academic\Http\Controllers\StudentActivityController;
use App\Modules\Academic\Http\Controllers\StudentOverviewController;
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
        // Importar preguntas al banco desde una hoja
        Route::get('/question-banks/{bank}/import/template', [QuestionImportController::class, 'template'])->whereUuid('bank');
        Route::post('/question-banks/{bank}/import/preview', [QuestionImportController::class, 'preview'])->whereUuid('bank');
        Route::post('/question-banks/{bank}/import', [QuestionImportController::class, 'store'])->whereUuid('bank');
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
            // Paquete SCORM de la actividad (.zip)
            Route::post('/activities/{activity}/scorm', [ScormController::class, 'upload'])->middleware('throttle:20,1');
        });

    // Seguimiento: quien revisa todos los cursos o el profesor en los suyos
    Route::middleware('permission:submissions.review,submissions.assigned.review')
        ->prefix('/courses/{course}')
        ->whereUuid(['course', 'submission', 'activity', 'enrollment'])
        ->group(function () {
            Route::get('/tracking', [ReviewController::class, 'tracking']);
            Route::get('/gradebook/export', [ReviewController::class, 'exportGradebook']);
            Route::get('/submissions', [ReviewController::class, 'submissions']);
            Route::post('/submissions/{submission}/grade', [ReviewController::class, 'grade']);
            Route::post('/submissions/{submission}/return', [ReviewController::class, 'returnSubmission']);
            Route::get('/submissions/{submission}/download', [ReviewController::class, 'download']);
            // Intentos de un alumno en un cuestionario, con el detalle de cada uno
            Route::get('/enrollments/{enrollment}/activities/{activity}/attempts', [ReviewController::class, 'attempts']);
            Route::post('/enrollments/{enrollment}/activities/{activity}/extra-attempts', [ReviewController::class, 'grantAttempts']);
            Route::get('/activities/{activity}/survey-results', [ReviewController::class, 'surveyResults']);
            Route::delete('/enrollments/{enrollment}/activities/{activity}/scorm', [ScormController::class, 'reset']);
        });

    // Alumno: resumen de inicio
    Route::get('/me/overview', [StudentOverviewController::class, 'show'])->middleware('permission:enrollments.self.read');

    // Alumno: consultar sus actividades…
    Route::middleware('permission:activities.self')->whereUuid(['course', 'activity', 'attempt'])->group(function () {
        Route::get('/me/courses/{course}/activities', [StudentActivityController::class, 'index']);
        Route::get('/me/courses/{course}/grades', [StudentActivityController::class, 'grades']);
        Route::get('/me/courses/{course}/activities/{activity}', [StudentActivityController::class, 'show']);
        Route::get('/me/courses/{course}/activities/{activity}/submission/download', [StudentActivityController::class, 'downloadSubmission']);
        Route::get('/me/attempts/{attempt}', [StudentActivityController::class, 'showAttempt']);
    });

    // …y rendirlas (Maestro §7.2: activity.attempt)
    Route::middleware('permission:activities.attempt')->whereUuid(['course', 'activity', 'attempt'])->group(function () {
        Route::post('/me/courses/{course}/activities/{activity}/attempts', [StudentActivityController::class, 'startAttempt']);
        Route::put('/me/attempts/{attempt}/draft', [StudentActivityController::class, 'saveAttemptDraft']);
        Route::post('/me/attempts/{attempt}/submit', [StudentActivityController::class, 'submitAttempt']);
        Route::post('/me/courses/{course}/activities/{activity}/submission', [StudentActivityController::class, 'submitTask']);
        Route::post('/me/courses/{course}/activities/{activity}/survey', [StudentActivityController::class, 'answerSurvey']);
    });
});

// Vista previa del paquete SCORM para el personal (no guarda nada)
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:courses.read,courses.assigned.read'])
    ->get('/courses/{course}/activities/{activity}/scorm/preview', [ScormController::class, 'preview'])
    ->whereUuid(['course', 'activity']);

// El alumno abre el paquete y este guarda su avance
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:activities.attempt'])->whereUuid(['course', 'activity'])->group(function () {
    Route::get('/me/courses/{course}/activities/{activity}/scorm', [ScormController::class, 'launch']);
    Route::post('/me/courses/{course}/activities/{activity}/scorm', [ScormController::class, 'commit'])->middleware('throttle:120,1');
});

// Archivos del paquete para el marco del aula: el permiso va en el enlace firmado
Route::get('/scorm-content/{token}/{path}', ScormContentController::class)
    ->where('token', '[A-Za-z0-9.-]+')
    ->where('path', '.*')
    ->middleware('throttle:2000,1');
