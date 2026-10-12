<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Application\Payments\Online\GatewayErrorLog;
use App\Modules\Sales\Application\Payments\Online\OnlinePaymentService;
use App\Modules\Sales\Domain\Models\GatewayError;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cobro en línea de la organización: las cuentas que conecta en cada
 * pasarela, y los avisos de pago que esas pasarelas envían.
 */
class PaymentGatewayController extends Controller
{
    public function __construct(
        private readonly OnlinePaymentService $payments,
        private readonly GatewayErrorLog $errors,
    ) {}

    /**
     * Lo último que las pasarelas rechazaron o no respondieron con la cuenta de la organización.
     */
    public function errors(): JsonResponse
    {
        return ApiResponse::success($this->errors->latest(GatewayError::SCOPE_TENANT));
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->payments->overview());
    }

    /**
     * Con claves: conecta la cuenta (se comprueban con la pasarela). Sin
     * claves: activa o pausa una cuenta ya conectada, o cambia su tipo de
     * cambio (soles por dólar, para la pasarela que cobra en dólares).
     */
    public function update(Request $request, string $gateway): JsonResponse
    {
        $gateway = strtoupper($gateway);
        $rules = $this->payments->gateway($gateway)->credentialRules();
        $connecting = $request->filled(array_key_first($rules));

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0.1', 'max:100'],
        ] + ($connecting ? $rules : []));

        if ($connecting) {
            $this->payments->connect($gateway, array_intersect_key($data, $rules), (bool) ($data['enabled'] ?? true));
        } elseif (isset($data['enabled'])) {
            $this->payments->setEnabled($gateway, (bool) $data['enabled']);
        }

        if (isset($data['exchange_rate'])) {
            $this->payments->setExchangeRate($gateway, (float) $data['exchange_rate'], $request->user());
        }

        return $this->index();
    }

    public function destroy(string $gateway): JsonResponse
    {
        $gateway = strtoupper($gateway);
        $this->payments->gateway($gateway);
        $this->payments->disconnect($gateway);

        return $this->index();
    }

    /**
     * Aviso de la pasarela: no trae sesión. Se responde 200 aunque no haya
     * nada que hacer, para que la pasarela no lo repita.
     */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        $gateway = strtoupper($gateway);
        $this->payments->gateway($gateway);
        $this->payments->handleWebhook($gateway, $request);

        return ApiResponse::success(['received' => true]);
    }
}
