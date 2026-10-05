<?php

use App\Modules\Audit\Http\Controllers\AuditEventController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    Route::get('/audit-events', [AuditEventController::class, 'index'])->middleware('permission:audit.read');
});
