<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\PushNotifier;
use App\Modules\Tenancy\Application\TenantUrls;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Mi cuenta» → avisos al teléfono: cada persona los activa en cada uno de sus dispositivos.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly PushNotifier $push) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'enabled' => PushNotifier::enabled(),
            // Llave pública con la que el navegador pide la suscripción (no es secreta)
            'public_key' => PushNotifier::enabled() ? config('services.webpush.public_key') : null,
            'devices' => $this->push->devices($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ], [
            'endpoint.url' => 'El navegador no entregó una dirección válida para los avisos.',
        ]);

        $this->push->subscribe($request->user(), $data, $request->userAgent());

        return ApiResponse::success(['devices' => $this->push->devices($request->user())], status: 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        $this->push->unsubscribe($request->user(), $data['endpoint']);

        return ApiResponse::success(['devices' => $this->push->devices($request->user())]);
    }

    /**
     * Un aviso de prueba a todos los dispositivos de quien lo pide.
     */
    public function test(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        if ($this->push->devices($user) === 0) {
            throw new ApiException(ErrorCode::Conflict, 'Activa primero los avisos en este dispositivo.', 409);
        }

        $this->push->notify($tenant, $user, "Avisos de {$tenant->name}", 'Así te llegarán los avisos de tus cursos, recordatorios y anuncios.', TenantUrls::base($tenant).'/account', 'prueba');

        return ApiResponse::success(['sent' => true]);
    }

    private function ensureEnabled(): void
    {
        if (! PushNotifier::enabled()) {
            throw new ApiException(ErrorCode::Conflict, 'Los avisos al teléfono todavía no están activos en este servidor.', 409);
        }
    }
}
