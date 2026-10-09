<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\Payments\Online\Charge;
use App\Modules\Sales\Application\Payments\Online\GatewayPayment;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pago en línea de una orden de plan, con la cuenta de la plataforma en la
 * pasarela. Como en la venta de cursos, el pago siempre se consulta en la
 * pasarela: ni su aviso ni el navegador de quien paga se dan por ciertos.
 */
class PlanOnlinePayments
{
    public function __construct(
        private readonly PlatformGateways $gateways,
        private readonly PlanOrderService $orders,
        private readonly TenantContext $context,
    ) {}

    /**
     * Pasarelas con las que la organización puede pagar esta orden ahora.
     *
     * @return list<array{code: string, label: string}>
     */
    public function availableFor(?PlanOrder $order): array
    {
        if ($order === null || ! $order->isPending() || (float) $order->amount <= 0) {
            return [];
        }

        return $this->gateways->availableFor($order->currency);
    }

    /**
     * Prepara el cobro en la pasarela y devuelve a dónde enviar a quien paga.
     *
     * @param  array{return: string, webhook: ?string}  $urls
     */
    public function start(PlanOrder $order, string $code, User $payer, array $urls): string
    {
        if (! in_array($code, array_column($this->availableFor($order), 'code'), true)) {
            throw new ApiException(ErrorCode::GatewayNotAvailable, 'Esta orden no se puede pagar por ese medio.', 409);
        }

        $checkout = $this->gateways->gateway($code)->startCheckout($this->gateways->credentials($code), new Charge(
            reference: $order->id,
            title: "IntelectaLab · Plan {$order->plan_name} ({$order->code()})",
            amount: (string) $order->amount,
            currency: $order->currency,
            payerEmail: $payer->email,
            payerFirstName: $payer->first_name,
            payerLastName: $payer->last_name,
            expiresAt: $order->expires_at,
        ), $urls);

        $order->update(['gateway' => $code, 'gateway_checkout_id' => $checkout['external_id']]);

        return $checkout['checkout_url'];
    }

    /**
     * Pregunta a la pasarela si la orden ya se pagó (la organización volvió
     * del checkout, o el aviso no llegó) y, si es así, la confirma.
     */
    public function sync(PlanOrder $order): PlanOrder
    {
        $account = $order->gateway ? $this->gateways->credentials($order->gateway) : null;

        if ($account === null || ! in_array($order->status, [PlanOrder::STATUS_PENDING, PlanOrder::STATUS_EXPIRED], true)) {
            return $order;
        }

        foreach ($this->gateways->gateway($order->gateway)->paymentsFor($account, $order->id) as $payment) {
            if ($payment->isApproved()) {
                return $this->apply($order, $payment, $order->gateway);
            }
        }

        return $order;
    }

    /**
     * Aviso de la pasarela a la plataforma. Llega sin sesión: la orden se
     * busca con acceso a todas las organizaciones.
     */
    public function handleWebhook(string $code, Request $request): void
    {
        $account = $this->gateways->credentials($code);

        if ($account === null) {
            return;
        }

        $payment = $this->gateways->gateway($code)->paymentFromWebhook($account, $request);

        if ($payment === null || ! Str::isUuid((string) $payment->orderReference)) {
            return;
        }

        $this->context->runAsSystem(function () use ($payment, $code) {
            $order = PlanOrder::query()->find($payment->orderReference);

            if ($order !== null) {
                $this->apply($order, $payment, $code);
            }
        });
    }

    /**
     * Solo un pago aprobado, por el importe y la moneda de la orden, la deja
     * pagada. Lo que no cuadra queda para que lo resuelva la plataforma.
     */
    private function apply(PlanOrder $order, GatewayPayment $payment, string $code): PlanOrder
    {
        if (! $payment->isApproved()) {
            return $order;
        }

        return DB::transaction(function () use ($order, $payment, $code) {
            $order = PlanOrder::query()->lockForUpdate()->findOrFail($order->id);

            // El mismo pago avisado otra vez
            if ($order->gateway_payment_id === $payment->id) {
                return $order;
            }

            $problem = match (true) {
                $payment->currency !== $order->currency || $payment->amount !== number_format((float) $order->amount, 2, '.', '') => "La pasarela cobró {$payment->currency} {$payment->amount} (pago n.º {$payment->id}) y la orden es por {$order->currency} {$order->amount}.",
                $order->status === PlanOrder::STATUS_PAID => "La orden ya estaba pagada: el pago n.º {$payment->id} es un cobro de más.",
                $order->status === PlanOrder::STATUS_CANCELLED => "La orden estaba cancelada cuando llegó el pago n.º {$payment->id}.",
                default => null,
            };

            if ($problem !== null) {
                $this->orders->flagForReview($order, $problem);

                return $order;
            }

            $gateway = $this->gateways->gateway($code);
            $order->update(['gateway' => $code]);

            return $this->orders->confirm($order, ['gateway' => $code, 'method' => $payment->method, 'reference' => $payment->id], $gateway->label());
        });
    }
}
