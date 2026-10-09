<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Application\Payments\Online\OnlinePaymentService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cobro en línea de la organización: las cuentas que conecta en cada
 * pasarela, y los avisos de pago que esas pasarelas envían.
 */
class PaymentGatewayController extends Controller
{
    public function __construct(private readonly OnlinePaymentService $payments) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->payments->overview());
    }

    /**
     * Con claves: conecta la cuenta (se comprueban con la pasarela). Sin
     * claves: solo activa o pausa una cuenta ya conectada.
     */
    public function update(Request $request, string $gateway): JsonResponse
    {
        $gateway = strtoupper($gateway);
        $rules = $this->payments->gateway($gateway)->credentialRules();
        $connecting = $request->filled(array_key_first($rules));

        $data = $request->validate(['enabled' => ['sometimes', 'boolean']] + ($connecting ? $rules : []));
        $enabled = (bool) ($data['enabled'] ?? true);

        if ($connecting) {
            $this->payments->connect($gateway, array_intersect_key($data, $rules), $enabled);
        } else {
            $this->payments->setEnabled($gateway, $enabled);
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
