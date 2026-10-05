<?php

use App\Modules\Files\Http\Controllers\PublicFileController;
use Illuminate\Support\Facades\Route;

// Archivos públicos: sin sesión, acotados a la organización del subdominio.
// Los privados no tienen ruta genérica: los entrega el módulo dueño con sus reglas.
Route::get('/public-files/{file}', [PublicFileController::class, 'show'])
    ->whereUuid('file')
    ->middleware('tenant');
