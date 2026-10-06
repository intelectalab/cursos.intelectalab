<?php

use App\Modules\Support\Http\Controllers\MyTicketController;
use App\Modules\Support\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Quien consulta: sus propios tickets
    Route::middleware('permission:tickets.self')->group(function () {
        Route::get('/me/tickets', [MyTicketController::class, 'index']);
        Route::get('/me/tickets/options', [MyTicketController::class, 'options']);
        Route::get('/me/tickets/{ticket}', [MyTicketController::class, 'show'])->whereUuid('ticket');
        Route::post('/me/tickets/{ticket}/messages', [MyTicketController::class, 'reply'])->whereUuid('ticket')->middleware('throttle:30,1');
        Route::post('/me/tickets/{ticket}/close', [MyTicketController::class, 'close'])->whereUuid('ticket');
    });

    // Maestro §7.2
    Route::post('/tickets', [MyTicketController::class, 'store'])->middleware(['permission:tickets.create', 'throttle:10,1']);

    // Adjuntos: el propio controlador decide si quien pide puede verlo
    Route::get('/tickets/attachments/{attachment}', [TicketController::class, 'attachment'])
        ->whereUuid('attachment')->middleware('permission:tickets.self,tickets.read');

    // Personal de soporte: todos los tickets de la organización
    Route::middleware('permission:tickets.read')->group(function () {
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::get('/tickets/agents', [TicketController::class, 'agents']);
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->whereUuid('ticket');
    });

    Route::middleware('permission:tickets.manage')->group(function () {
        Route::put('/tickets/{ticket}', [TicketController::class, 'update'])->whereUuid('ticket');
        Route::post('/tickets/{ticket}/messages', [TicketController::class, 'reply'])->whereUuid('ticket');
        Route::post('/tickets/{ticket}/status', [TicketController::class, 'changeStatus'])->whereUuid('ticket');
    });
});
