<?php

use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Todo el módulo opera dentro del subdominio de una organización
Route::middleware('tenant')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AccountController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [AccountController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'tenant.user'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Cuenta propia: no requiere permisos adicionales
        Route::put('/auth/password', [AccountController::class, 'changePassword']);
        Route::get('/auth/sessions', [AccountController::class, 'sessions']);
        Route::delete('/auth/sessions', [AccountController::class, 'revokeOtherSessions']);
        Route::delete('/auth/sessions/{session}', [AccountController::class, 'revokeSession'])->whereNumber('session');

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.read');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('permission:users.update');

        Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.read');
        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.read');
        Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
    });
});
