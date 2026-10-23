<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\PlatformMailer;
use App\Modules\Tenancy\Http\Controllers\MailSettingsController;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consola → Correos → «Correo de envío de la plataforma»: el super
 * administrador elige con qué servidor salen los correos de la plataforma.
 */
class PlatformMailController extends Controller
{
    public function __construct(private readonly PlatformMailer $mailer) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->mailer->current());
    }

    public function update(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mailer->save(MailSettingsController::validated($request), $request->user()->email));
    }

    public function test(Request $request): JsonResponse
    {
        $to = $request->validate(['test_to' => ['nullable', 'email', 'max:190']], [], ['test_to' => 'correo para la prueba'])['test_to'] ?? $request->user()->email;
        $data = MailSettingsController::validated($request);

        $this->mailer->test($data, new TenantNoticeMail(
            organization: $data['from_name'] ?: (string) config('mail.from.name'),
            logoUrl: null,
            title: 'Prueba de correo de la plataforma',
            greeting: 'Hola:',
            lines: ['Este es un correo de prueba. Si lo recibiste, los correos de la plataforma saldrán con esta configuración.'],
        ), $to);

        return ApiResponse::success(['sent_to' => $to, 'delivered' => $data['mode'] === PlatformMailer::MODE_OWN || $this->mailer->delivers()]);
    }
}
