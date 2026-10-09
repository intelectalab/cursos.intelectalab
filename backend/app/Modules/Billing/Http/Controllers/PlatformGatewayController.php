<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\PlanOnlinePayments;
use App\Modules\Billing\Application\PlatformGateways;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consola de plataforma: las cuentas con las que la plataforma cobra los
 * planes en línea, y los avisos de pago que esas pasarelas le envían.
 */
class PlatformGatewayController extends Controller
{
    public function __construct(
        private readonly PlatformGateways $gateways,
        private readonly PlanOnlinePayments $payments,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->gateways->overview());
    }

    /**
     * Con claves: conecta la cuenta (se comprueban con la pasarela). Sin
     * claves: solo activa o pausa una cuenta ya conectada.
     */
    public function update(Request $request, string $gateway): JsonResponse
    {
        $gateway = strtoupper($gateway);
        $rules = $this->gateways->gateway($gateway)->credentialRules();
        $connecting = $request->filled(array_key_first($rules));

        $data = $request->validate(['enabled' => ['sometimes', 'boolean']] + ($connecting ? $rules : []));
        $enabled = (bool) ($data['enabled'] ?? true);

        if ($connecting) {
            $this->gateways->connect($gateway, array_intersect_key($data, $rules), $enabled);
        } else {
            $this->gateways->setEnabled($gateway, $enabled);
        }

        return $this->index();
    }

    public function destroy(string $gateway): JsonResponse
    {
        $gateway = strtoupper($gateway);
        $this->gateways->gateway($gateway);
        $this->gateways->disconnect($gateway);

        return $this->index();
    }

    /**
     * Aviso de la pasarela: no trae sesión. Se responde 200 aunque no haya
     * nada que hacer, para que la pasarela no lo repita.
     */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        $gateway = strtoupper($gateway);
        $this->gateways->gateway($gateway);
        $this->payments->handleWebhook($gateway, $request);

        return ApiResponse::success(['received' => true]);
    }
}
