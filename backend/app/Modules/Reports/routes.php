<?php

use App\Modules\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Reportes de la organización (Maestro §7.2, GET /reports/{report})
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:reports.read'])->group(function () {
    Route::get('/reports/overview', [ReportController::class, 'overview']);
});
