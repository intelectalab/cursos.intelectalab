<?php

use App\Modules\Tenancy\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('/tenant', [TenantController::class, 'current']);

// Alta de organizaciones: solo desde el dominio central
Route::post('/tenants', [TenantController::class, 'register'])
    ->middleware(['central', 'throttle:10,1']);
