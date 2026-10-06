<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Limpieza diaria de sesiones vencidas (RF-AUTH-002)
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Órdenes pendientes cuyo plazo de pago ya pasó (S5)
Schedule::command('orders:expire')->hourly();

// Tickets resueltos sin respuesta durante una semana (S10)
Schedule::command('tickets:close-resolved')->daily();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
