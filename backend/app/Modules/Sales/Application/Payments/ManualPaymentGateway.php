<?php

namespace App\Modules\Sales\Application\Payments;

use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Application\TenantSettingsService;

/**
 * Cobro fuera del sistema: el comprador paga como indica la organización
 * (transferencia, Yape, efectivo) y el personal confirma el pago recibido.
 */
class ManualPaymentGateway implements PaymentGateway
{
    public const CODE = 'MANUAL';

    public function __construct(private readonly TenantSettingsService $settings) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function instructions(Order $order): array
    {
        return [
            'type' => self::CODE,
            'text' => $this->settings->paymentInstructions(),
            'methods' => Payment::METHODS,
        ];
    }
}
