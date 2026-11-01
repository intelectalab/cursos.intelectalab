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

// Aviso a los alumnos de las clases en vivo del día: corre cada hora y sale a las 7:30 de cada academia
Schedule::command('live-classes:remind')->hourlyAt(30);

// «Respaldo ahora» de la consola: el pedido se hace aquí, sin el tope de tiempo de la web ni de la cola
Schedule::command('platform-backups:run')->everyMinute()->withoutOverlapping(120)->runInBackground();

// Aviso a la plataforma si el último respaldo falló o hace más de 48 horas que no hay uno bueno
Schedule::command('platform-backups:check')->dailyAt('09:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
