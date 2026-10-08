<?php

use App\Modules\Reports\Http\Controllers\ReportController;
use App\Modules\Reports\Http\Controllers\StudentController;
use App\Modules\Reports\Http\Controllers\TeacherController;
use Illuminate\Support\Facades\Route;

// Reportes de la organización (Maestro §7.2, GET /reports/{report})
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:reports.read'])->group(function () {
    Route::get('/reports/overview', [ReportController::class, 'overview']);
});

// Alumnos y su ficha completa (Alumno 360): lectura que reúne matrículas, compras, certificados y soporte
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:students.read'])->group(function () {
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{student}', [StudentController::class, 'show'])->whereUuid('student');
});

// Docentes: sus cursos asignados y su carga de calificación
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:teachers.read'])->group(function () {
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::get('/teachers/{teacher}', [TeacherController::class, 'show'])->whereUuid('teacher');
});
