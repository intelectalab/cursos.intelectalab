<?php

use App\Modules\Tenancy\Http\Controllers\AppearanceController;
use App\Modules\Tenancy\Http\Controllers\EmailPreferencesController;
use App\Modules\Tenancy\Http\Controllers\EmailTemplateController;
use App\Modules\Tenancy\Http\Controllers\GradeScaleController;
use App\Modules\Tenancy\Http\Controllers\HolidayController;
use App\Modules\Tenancy\Http\Controllers\MailSettingsController;
use App\Modules\Tenancy\Http\Controllers\PlatformEmailTemplateController;
use App\Modules\Tenancy\Http\Controllers\TenantController;
use App\Modules\Tenancy\Http\Controllers\TenantSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/tenant', [TenantController::class, 'current']);

// Alta de organizaciones: solo desde el dominio central
Route::post('/tenants', [TenantController::class, 'register'])
    ->middleware(['central', 'throttle:10,1']);

// Correo de envío de la academia: su propio servidor de correo o el de la plataforma con su nombre
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:email_templates.manage'])->prefix('/settings/mail')->group(function () {
    Route::get('/', [MailSettingsController::class, 'show']);
    Route::put('/', [MailSettingsController::class, 'update']);
    Route::post('/test', [MailSettingsController::class, 'test'])->middleware('throttle:5,1');
});

// Correos automáticos: la academia cambia sus textos, apaga los que no le sirven y los prueba
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user', 'permission:email_templates.manage'])->prefix('/settings/emails')->group(function () {
    Route::get('/', [EmailTemplateController::class, 'index']);
    Route::get('/{key}', [EmailTemplateController::class, 'show'])->where('key', '[a-z_]+');
    Route::put('/{key}', [EmailTemplateController::class, 'update'])->where('key', '[a-z_]+');
    Route::delete('/{key}', [EmailTemplateController::class, 'destroy'])->where('key', '[a-z_]+');
    Route::post('/{key}/preview', [EmailTemplateController::class, 'preview'])->where('key', '[a-z_]+')->middleware('throttle:60,1');
    Route::post('/{key}/test', [EmailTemplateController::class, 'test'])->where('key', '[a-z_]+')->middleware('throttle:10,1');
});

// Consola: correos de la plataforma y texto base de los de las academias
Route::prefix('platform/emails')->middleware(['central', 'auth:sanctum', 'platform.admin'])->group(function () {
    Route::get('/', [PlatformEmailTemplateController::class, 'index']);
    Route::get('/{key}', [PlatformEmailTemplateController::class, 'show'])->where('key', '[a-z_]+');
    Route::put('/{key}', [PlatformEmailTemplateController::class, 'update'])->where('key', '[a-z_]+');
    Route::delete('/{key}', [PlatformEmailTemplateController::class, 'destroy'])->where('key', '[a-z_]+');
    Route::post('/{key}/preview', [PlatformEmailTemplateController::class, 'preview'])->where('key', '[a-z_]+')->middleware('throttle:60,1');
    Route::post('/{key}/test', [PlatformEmailTemplateController::class, 'test'])->where('key', '[a-z_]+')->middleware('throttle:10,1');
});

// Darse de baja de correos: desde el enlace del correo (sin sesión) y con un clic desde el programa de correo
Route::middleware('tenant')->prefix('/email-preferences/{token}')->where(['token' => '[A-Za-z0-9_-]+\.[a-f0-9]{32}'])->group(function () {
    Route::get('/', [EmailPreferencesController::class, 'show'])->middleware('throttle:30,1');
    Route::put('/', [EmailPreferencesController::class, 'update'])->middleware('throttle:10,1');
    Route::post('/unsubscribe', [EmailPreferencesController::class, 'oneClick'])->middleware('throttle:10,1');
});

// Los correos que recibe quien inició sesión («Mi cuenta»)
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->prefix('/auth/email-preferences')->group(function () {
    Route::get('/', [EmailPreferencesController::class, 'mine']);
    Route::put('/', [EmailPreferencesController::class, 'updateMine']);
});

// Configuración general de la organización
Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    Route::get('/settings', [TenantSettingsController::class, 'show'])->middleware('permission:tenant_settings.read');
    Route::put('/settings', [TenantSettingsController::class, 'update'])->middleware('permission:tenant_settings.update');
    Route::post('/settings/logo', [TenantSettingsController::class, 'uploadLogo'])->middleware('permission:tenant_settings.update');
    Route::delete('/settings/logo', [TenantSettingsController::class, 'removeLogo'])->middleware('permission:tenant_settings.update');

    // Apariencia: colores de la academia y su pantalla de acceso
    Route::get('/settings/appearance', [AppearanceController::class, 'show'])->middleware('permission:tenant_settings.read');
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::put('/settings/appearance', [AppearanceController::class, 'update']);
        Route::delete('/settings/appearance', [AppearanceController::class, 'reset']);
        Route::post('/settings/appearance/login-background', [AppearanceController::class, 'uploadBackground']);
        Route::delete('/settings/appearance/login-background', [AppearanceController::class, 'removeBackground']);
    });

    // Escalas de notas guardadas por la organización
    Route::get('/settings/grade-scales', [GradeScaleController::class, 'index'])->middleware('permission:tenant_settings.read');
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::post('/settings/grade-scales', [GradeScaleController::class, 'store']);
        Route::put('/settings/grade-scales/{scale}', [GradeScaleController::class, 'update'])->whereUuid('scale');
        Route::delete('/settings/grade-scales/{scale}', [GradeScaleController::class, 'destroy'])->whereUuid('scale');
    });

    // Feriados: no cuentan como días hábiles en los plazos (Libro de Reclamaciones)
    Route::get('/settings/holidays', [HolidayController::class, 'index'])->middleware('permission:tenant_settings.read');
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::post('/settings/holidays', [HolidayController::class, 'store']);
        Route::post('/settings/holidays/load-national', [HolidayController::class, 'loadNational']);
        Route::delete('/settings/holidays/{holiday}', [HolidayController::class, 'destroy'])->whereUuid('holiday');
    });
});
