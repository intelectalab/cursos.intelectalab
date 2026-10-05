<?php

use App\Modules\Certificates\Http\Controllers\CertificateController;
use App\Modules\Certificates\Http\Controllers\MyCertificateController;
use App\Modules\Certificates\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

// Verificación pública por código o QR: sin sesión
Route::get('/certificates/verify/{code}', [VerificationController::class, 'show'])
    ->where('code', '[A-Za-z0-9\-]{1,40}')
    ->middleware(['tenant', 'throttle:30,1']);

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Alumno: sus certificados
    Route::middleware('permission:certificates.self')->group(function () {
        Route::get('/me/certificates', [MyCertificateController::class, 'index']);
        Route::get('/me/certificates/{certificate}/pdf', [MyCertificateController::class, 'pdf'])->whereUuid('certificate');
    });

    // Personal: certificados de la organización
    Route::middleware('permission:certificates.read,certificates.issue')->group(function () {
        Route::get('/certificates', [CertificateController::class, 'index']);
        Route::get('/certificates/template', [CertificateController::class, 'template']);
        Route::get('/certificates/template/signature', [CertificateController::class, 'signature']);
        Route::get('/certificates/{certificate}/pdf', [CertificateController::class, 'pdf'])->whereUuid('certificate');
    });

    Route::middleware('permission:certificates.issue')->group(function () {
        Route::get('/certificates/candidates', [CertificateController::class, 'candidates']);
        // Maestro §7.2
        Route::post('/certificates/issue', [CertificateController::class, 'issue']);
    });

    Route::post('/certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])
        ->whereUuid('certificate')->middleware('permission:certificates.revoke');

    // La plantilla es configuración de la organización
    Route::middleware('permission:tenant_settings.update')->group(function () {
        Route::put('/certificates/template', [CertificateController::class, 'updateTemplate']);
        Route::post('/certificates/template/signature', [CertificateController::class, 'uploadSignature']);
        Route::delete('/certificates/template/signature', [CertificateController::class, 'removeSignature']);
    });
});
