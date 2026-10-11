<?php

use App\Modules\Enrollments\Http\Controllers\AvailableCoursesController;
use App\Modules\Enrollments\Http\Controllers\CoursePreviewController;
use App\Modules\Enrollments\Http\Controllers\EnrollmentController;
use App\Modules\Enrollments\Http\Controllers\MyCoursesController;
use App\Modules\Enrollments\Http\Controllers\ParticipantController;
use App\Modules\Enrollments\Http\Controllers\PeopleImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Personal: todas las matrículas de la organización. Soporte las consulta en solo lectura (Maestro §8.1).
    Route::middleware('permission:enrollments.read,enrollments.support.read')->group(function () {
        Route::get('/enrollments', [EnrollmentController::class, 'all']);
        Route::get('/enrollments/options', [EnrollmentController::class, 'options']);
        Route::get('/enrollments/export', [EnrollmentController::class, 'export']);
    });

    Route::post('/enrollments', [EnrollmentController::class, 'store'])->middleware('permission:enrollments.create');
    Route::post('/enrollments/bulk-cancel', [EnrollmentController::class, 'bulkCancel'])->middleware('permission:enrollments.cancel');
    Route::post('/enrollments/bulk-validity', [EnrollmentController::class, 'bulkValidity'])->middleware('permission:enrollments.update');
    Route::put('/enrollments/{enrollment}', [EnrollmentController::class, 'update'])
        ->whereUuid('enrollment')->middleware('permission:enrollments.update');
    Route::delete('/enrollments/{enrollment}', [EnrollmentController::class, 'cancel'])
        ->whereUuid('enrollment')->middleware('permission:enrollments.cancel');

    // Personal: matrículas de un curso. La lista también la ve quien hace su seguimiento (el profesor).
    Route::prefix('/courses/{course}')->whereUuid(['course', 'enrollment'])->group(function () {
        Route::get('/enrollments', [EnrollmentController::class, 'index'])
            ->middleware('permission:enrollments.read,submissions.review,submissions.assigned.review');
        Route::get('/enrollment-candidates', [EnrollmentController::class, 'candidates'])->middleware('permission:enrollments.create');
        Route::post('/enrollments', [EnrollmentController::class, 'storeForCourse'])->middleware('permission:enrollments.create');
        Route::delete('/enrollments/{enrollment}', [EnrollmentController::class, 'cancelForCourse'])->middleware('permission:enrollments.cancel');

        // Participantes: alumnos y docentes se agregan desde el mismo lugar, cada uno con su papel en el curso
        Route::get('/participant-candidates', [ParticipantController::class, 'candidates'])->middleware('permission:enrollments.create,courses.update');
        Route::post('/participants', [ParticipantController::class, 'store'])->middleware('permission:enrollments.create,courses.update');
        Route::delete('/teachers/{user}', [ParticipantController::class, 'removeTeacher'])->whereUuid('user')->middleware('permission:courses.update');
    });

    // Importar personas desde una hoja: crea las cuentas que falten, matricula y agrupa en una pasada
    Route::middleware('permission:users.create')->prefix('/imports/people')->group(function () {
        Route::get('/template', [PeopleImportController::class, 'template']);
        Route::post('/preview', [PeopleImportController::class, 'preview']);
        Route::post('/', [PeopleImportController::class, 'store']);
    });

    // Personal: el aula tal como la ve un alumno, de lo publicado o del borrador
    Route::get('/courses/{course}/preview', [CoursePreviewController::class, 'show'])
        ->whereUuid('course')
        ->middleware('permission:courses.read,courses.assigned.read');

    // Alumno: sus cursos (Maestro §7.2, GET /me/courses) y el aula
    Route::middleware('permission:enrollments.self.read')->group(function () {
        Route::get('/me/courses', [MyCoursesController::class, 'index']);
        Route::get('/me/courses/{course}', [MyCoursesController::class, 'show'])->whereUuid('course');
        Route::get('/me/courses/{course}/resources/{resource}/download', [MyCoursesController::class, 'download'])
            ->whereUuid(['course', 'resource']);
        Route::post('/me/courses/{course}/resources/{resource}/complete', [MyCoursesController::class, 'completeResource'])
            ->whereUuid(['course', 'resource']);
    });

    // Alumno: cursos publicados en los que aún no está
    Route::middleware('permission:courses.catalog.read')->group(function () {
        Route::get('/me/available-courses', [AvailableCoursesController::class, 'index']);
        Route::get('/me/available-courses/{course}', [AvailableCoursesController::class, 'show'])->whereUuid('course');
    });
});
