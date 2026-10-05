<?php

use App\Modules\Enrollments\Http\Controllers\EnrollmentController;
use App\Modules\Enrollments\Http\Controllers\MyCoursesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Personal de la organización: matrículas de un curso
    Route::prefix('/courses/{course}')->whereUuid(['course', 'enrollment'])->group(function () {
        Route::get('/enrollments', [EnrollmentController::class, 'index'])->middleware('permission:enrollments.read');
        Route::get('/enrollment-candidates', [EnrollmentController::class, 'candidates'])->middleware('permission:enrollments.create');
        Route::post('/enrollments', [EnrollmentController::class, 'store'])->middleware('permission:enrollments.create');
        Route::delete('/enrollments/{enrollment}', [EnrollmentController::class, 'cancel'])->middleware('permission:enrollments.cancel');
    });

    // Alumno: sus cursos (Maestro §7.2, GET /me/courses) y el aula
    Route::middleware('permission:enrollments.self.read')->group(function () {
        Route::get('/me/courses', [MyCoursesController::class, 'index']);
        Route::get('/me/courses/{course}', [MyCoursesController::class, 'show'])->whereUuid('course');
        Route::get('/me/courses/{course}/resources/{resource}/download', [MyCoursesController::class, 'download'])
            ->whereUuid(['course', 'resource']);
    });
});
