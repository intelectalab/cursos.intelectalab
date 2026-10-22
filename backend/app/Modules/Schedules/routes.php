<?php

use App\Modules\Schedules\Http\Controllers\AttendanceController;
use App\Modules\Schedules\Http\Controllers\MyScheduleController;
use App\Modules\Schedules\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Personal: los horarios de todos los docentes y el editor de cada uno
    Route::middleware('permission:schedules.read')->group(function () {
        Route::get('/schedules', [ScheduleController::class, 'index']);
        Route::get('/schedules/{teacher}', [ScheduleController::class, 'show'])->whereUuid('teacher');
    });
    Route::put('/schedules/{teacher}', [ScheduleController::class, 'update'])->whereUuid('teacher')->middleware('permission:schedules.manage');

    // Docente: su horario, que confirma u observa
    Route::middleware('permission:schedules.self')->group(function () {
        Route::get('/me/schedule', [MyScheduleController::class, 'show']);
        Route::post('/me/schedule/confirm', [MyScheduleController::class, 'confirm']);
        Route::post('/me/schedule/observe', [MyScheduleController::class, 'observe'])->middleware('throttle:20,1');
    });

    // Alumno: las clases en vivo de sus cursos y su asistencia
    Route::middleware('permission:enrollments.self.read')->group(function () {
        Route::get('/me/live-classes', [MyScheduleController::class, 'liveClasses']);
        Route::get('/me/courses/{course}/attendance', [AttendanceController::class, 'mine'])->whereUuid('course');
    });

    // Asistencia a las clases en vivo: quien hace el seguimiento del curso (Administrador o su docente)
    Route::middleware('permission:submissions.review,submissions.assigned.review')->prefix('/courses/{course}/attendance')->whereUuid('course')->group(function () {
        Route::get('/', [AttendanceController::class, 'show']);
        Route::put('/', [AttendanceController::class, 'update']);
        Route::get('/summary', [AttendanceController::class, 'summary']);
    });
});
