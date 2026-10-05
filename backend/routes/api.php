<?php

use Illuminate\Support\Facades\Route;

// Cada módulo de dominio registra sus rutas en app/Modules/{Módulo}/routes.php
Route::prefix('v1')->group(function () {
    foreach (glob(app_path('Modules/*/routes.php')) as $moduleRoutes) {
        require $moduleRoutes;
    }
});
