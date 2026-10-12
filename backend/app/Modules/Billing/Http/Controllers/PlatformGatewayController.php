<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\PlanOnlinePayments;
use App\Modules\Billing\Application\PlatformGateways;
use App\Modules\Sales\Application\Payments\Online\GatewayErrorLog;
use App\Modules\Sales\Domain\Models\GatewayError;
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
        private readonly GatewayErrorLog $errors,
    ) {}

    /**
     * Lo último que las pasarelas rechazaron o no respondieron con la cuenta de la plataforma.
     */
    public function errors(): JsonResponse
    {
        return ApiResponse::success($this->errors->latest(GatewayError::SCOPE_PLATFORM));
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->gateways->overview());
    }

    /**
     * Con claves: conecta la cuenta (se comprueban con la pasarela). Sin
     * claves: activa o pausa una cuenta ya conectada, o cambia su tipo de
     * cambio (soles por dólar, para la pasarela que cobra en dólares).
     */
    public function update(Request $request, string $gateway): JsonResponse
    {
        $gateway = strtoupper($gateway);
        $rules = $this->gateways->gateway($gateway)->credentialRules();
        $connecting = $request->filled(array_key_first($rules));

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0.1', 'max:100'],
        ] + ($connecting ? $rules : []));

        if ($connecting) {
            $this->gateways->connect($gateway, array_intersect_key($data, $rules), (bool) ($data['enabled'] ?? true));
        } elseif (isset($data['enabled'])) {
            $this->gateways->setEnabled($gateway, (bool) $data['enabled']);
        }

        if (isset($data['exchange_rate'])) {
            $this->gateways->setExchangeRate($gateway, (float) $data['exchange_rate']);
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
