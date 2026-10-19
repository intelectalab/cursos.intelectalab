<?php

use App\Modules\Files\Http\Controllers\PublicFileController;
use Illuminate\Support\Facades\Route;

// Archivos públicos: sin sesión, acotados a la organización del subdominio (en el modo por ruta, por su identificador).
// Los privados no tienen ruta genérica: los entrega el módulo dueño con sus reglas.
// Sin el middleware de organización obligatoria: el controlador decide según el modo.
Route::get('/public-files/{file}', [PublicFileController::class, 'show'])
    ->whereUuid('file');
