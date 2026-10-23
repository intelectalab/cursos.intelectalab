<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\TenantMailer;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Domain\Models\TenantMailSetting;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Correo de envío de la academia (Configuración → Correos): su propio
 * servidor de correo o el de la plataforma con su nombre.
 */
class MailSettingsController extends Controller
{
    public function __construct(
        private readonly TenantMailer $mailer,
        private readonly TenantNotifier $notifier,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mailer->current($request->attributes->get('tenant')));
    }

    public function update(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mailer->save($request->attributes->get('tenant'), $this->validated($request)));
    }

    public function test(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        // Por defecto a quien prueba; se puede elegir otro buzón (las cuentas de prueba no reciben correo)
        $to = $request->validate(['test_to' => ['nullable', 'email', 'max:190']], [], ['test_to' => 'correo para la prueba'])['test_to'] ?? $request->user()->email;

        $mail = $this->notifier->mail($tenant, $request->user()->first_name, [
            'subject' => 'Prueba de correo',
            'lines' => ['Este es un correo de prueba. Si lo recibiste, tus avisos saldrán con esta configuración.'],
            'button' => null,
        ], null);

        $this->mailer->test($tenant, $this->validated($request), $mail, $to);

        return ApiResponse::success(['sent_to' => $to, 'delivered' => ! in_array(config('mail.default'), ['log', 'array'], true) || $request->input('mode') === TenantMailSetting::MODE_OWN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $own = $request->input('mode') === TenantMailSetting::MODE_OWN;

        return $request->validate([
            'mode' => ['required', Rule::in(TenantMailSetting::MODES)],
            'from_name' => ['nullable', 'string', 'max:100'],
            'reply_to' => ['nullable', 'email', 'max:190'],
            'from_address' => [$own ? 'required' : 'nullable', 'email', 'max:190'],
            'host' => [$own ? 'required' : 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => [$own ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'security' => ['nullable', Rule::in(TenantMailSetting::SECURITY)],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
        ], [
            'host.regex' => 'Escribe solo el nombre del servidor, por ejemplo smtp.elasticemail.com.',
        ], [
            'from_name' => 'nombre del remitente',
            'reply_to' => 'correo para respuestas',
            'from_address' => 'correo remitente',
            'host' => 'servidor de correo',
            'port' => 'puerto',
            'username' => 'usuario',
            'password' => 'contraseña',
        ]);
    }
}
