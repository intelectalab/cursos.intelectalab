<?php

use App\Modules\Reports\Http\Controllers\AlertController;
use App\Modules\Reports\Http\Controllers\ReportController;
use App\Modules\Reports\Http\Controllers\StudentController;
use App\Modules\Reports\Http\Controllers\TeacherController;
use Illuminate\Support\Facades\Route;

// Reportes de la organización (Maestro §7.2, GET /reports/{report})
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:reports.read'])->group(function () {
    Route::get('/reports/overview', [ReportController::class, 'overview']);
    Route::get('/reports/overview/export', [ReportController::class, 'exportOverview'])->middleware('permission:reports.export');
});

// Alumnos y su ficha completa (Alumno 360): lectura que reúne matrículas, compras, certificados y soporte
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:students.read'])->group(function () {
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/export', [StudentController::class, 'export']);
    Route::get('/students/{student}', [StudentController::class, 'show'])->whereUuid('student');
});

// Docentes: sus cursos asignados y su carga de calificación
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:teachers.read'])->group(function () {
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::get('/teachers/{teacher}', [TeacherController::class, 'show'])->whereUuid('teacher');
});

// Lo que espera al personal, para la campana de avisos: cada cifra solo llega a quien puede atenderla
Route::get('/alerts/summary', [AlertController::class, 'summary'])
    ->middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:payments.confirm,orders.read,tickets.read,cms.publish']);
