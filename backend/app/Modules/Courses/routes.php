<?php

use App\Modules\Courses\Http\Controllers\CatalogController;
use App\Modules\Courses\Http\Controllers\CourseCommunityController;
use App\Modules\Courses\Http\Controllers\CourseContentController;
use App\Modules\Courses\Http\Controllers\CourseController;
use Illuminate\Support\Facades\Route;

// Catálogo público de la organización: los cursos en venta, sin iniciar sesión
Route::middleware(['tenant', 'throttle:120,1'])->group(function () {
    Route::get('/catalog/courses', [CatalogController::class, 'index']);
    Route::get('/catalog/courses/{course}', [CatalogController::class, 'show'])->whereUuid('course');
});

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Consulta: todos los cursos (courses.read) o solo los asignados (profesor)
    Route::middleware('permission:courses.read,courses.assigned.read')->group(function () {
        Route::get('/courses', [CourseController::class, 'index']);
        Route::get('/courses/options', [CourseController::class, 'options']);
        Route::get('/courses/{course}', [CourseController::class, 'show'])->whereUuid('course');
        Route::get('/courses/{course}/versions/{version}', [CourseController::class, 'version'])->whereUuid(['course', 'version']);
        Route::get('/courses/{course}/resources/{resource}/download', [CourseContentController::class, 'download'])
            ->whereUuid(['course', 'resource']);
    });

    // Anuncios y reseñas de un curso: quien ve el curso los consulta; publicar y moderar piden su permiso
    Route::middleware('permission:courses.read,courses.assigned.read')->prefix('/courses/{course}')->whereUuid(['course', 'announcement', 'review'])->group(function () {
        Route::get('/announcements', [CourseCommunityController::class, 'announcements']);
        Route::post('/announcements', [CourseCommunityController::class, 'publish'])->middleware('throttle:20,1');
        Route::put('/announcements/{announcement}', [CourseCommunityController::class, 'updateAnnouncement']);
        Route::delete('/announcements/{announcement}', [CourseCommunityController::class, 'deleteAnnouncement']);
        Route::get('/reviews', [CourseCommunityController::class, 'reviews']);
        Route::post('/reviews/{review}/hide', [CourseCommunityController::class, 'hide'])->middleware('permission:reviews.moderate');
        Route::post('/reviews/{review}/show', [CourseCommunityController::class, 'show'])->middleware('permission:reviews.moderate');
        Route::put('/reviews/{review}/response', [CourseCommunityController::class, 'respond'])->middleware('permission:reviews.moderate');
    });

    // Alumno: los anuncios de su curso y su reseña
    Route::middleware('permission:enrollments.self.read')->prefix('/me/courses/{course}')->whereUuid('course')->group(function () {
        Route::get('/announcements', [CourseCommunityController::class, 'myAnnouncements']);
        Route::get('/review', [CourseCommunityController::class, 'myReview']);
        Route::put('/review', [CourseCommunityController::class, 'saveMyReview'])->middleware('throttle:10,1');
    });

    Route::post('/courses', [CourseController::class, 'store'])->middleware('permission:courses.create');

    Route::middleware('permission:courses.update')->group(function () {
        Route::put('/courses/{course}', [CourseController::class, 'update'])->whereUuid('course');
        Route::post('/courses/{course}/cover', [CourseController::class, 'uploadCover'])->whereUuid('course');
        Route::delete('/courses/{course}/cover', [CourseController::class, 'removeCover'])->whereUuid('course');
        Route::put('/courses/{course}/teachers', [CourseController::class, 'setTeachers'])->whereUuid('course');
    });

    // Publicar es un permiso aparte de editar (Maestro RN-017)
    Route::middleware('permission:courses.publish')->group(function () {
        Route::post('/courses/{course}/publish', [CourseController::class, 'publish'])->whereUuid('course');
        Route::post('/courses/{course}/archive', [CourseController::class, 'archive'])->whereUuid('course');
        Route::post('/courses/{course}/restore', [CourseController::class, 'restore'])->whereUuid('course');
    });

    // Contenido: de cualquier curso (courses.content.write) o de los asignados (profesor)
    Route::middleware('permission:courses.content.write,content.assigned.write')
        ->prefix('/courses/{course}')
        ->whereUuid(['course', 'section', 'resource'])
        ->group(function () {
            Route::post('/sections', [CourseContentController::class, 'storeSection']);
            Route::put('/sections/order', [CourseContentController::class, 'reorderSections']);
            Route::put('/sections/{section}', [CourseContentController::class, 'updateSection']);
            Route::delete('/sections/{section}', [CourseContentController::class, 'destroySection']);
            Route::post('/sections/{section}/resources', [CourseContentController::class, 'storeResource']);
            Route::put('/sections/{section}/resources/order', [CourseContentController::class, 'reorderResources']);
            Route::post('/resources/{resource}', [CourseContentController::class, 'updateResource']);
            Route::delete('/resources/{resource}', [CourseContentController::class, 'destroyResource']);
        });
});
