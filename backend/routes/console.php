<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Limpieza diaria de sesiones vencidas (RF-AUTH-002)
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Órdenes pendientes cuyo plazo de pago ya pasó (S5)
Schedule::command('orders:expire')->hourly();

// Órdenes de plan que pasaron su plazo sin pago informado
Schedule::command('plan-orders:expire')->hourly();

// Tickets resueltos sin respuesta durante una semana (S10)
Schedule::command('tickets:close-resolved')->daily();

// Claves de idempotencia vencidas (S12)
Schedule::command('idempotency:prune')->daily();

// Tipo de cambio de las organizaciones que lo tienen en automático. SUNAT lo publica a primera hora
Schedule::command('exchange-rates:sync')->dailyAt('07:00');

// Recordatorios por correo: cuotas por vencer y vencidas, y accesos a cursos por terminar
Schedule::command('reminders:send')->dailyAt('08:00');

// Aviso a los alumnos de las clases en vivo del día
Schedule::command('live-classes:remind')->dailyAt('07:30');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
