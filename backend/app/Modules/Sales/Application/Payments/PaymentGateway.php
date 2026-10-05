<?php

namespace App\Modules\Sales\Application\Payments;

use App\Modules\Sales\Domain\Models\Order;

/**
 * Medio por el que se cobra una orden (Maestro §9.1). El dominio no conoce
 * al proveedor: la pasarela dice al comprador cómo pagar y, cuando el pago
 * llega, lo aplica con OrderService::applyPayment(). El proveedor real está
 * pendiente (PD-001, PD-002); mientras tanto se usa ManualPaymentGateway.
 */
interface PaymentGateway
{
    /** Código que queda registrado en cada pago (payments.gateway) */
    public function code(): string;

    /**
     * Qué debe hacer el comprador para pagar una orden pendiente.
     *
     * @return array{type: string, text: ?string, methods: list<string>}
     */
    public function instructions(Order $order): array;
}
