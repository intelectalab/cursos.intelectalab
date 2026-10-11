<?php

namespace App\Modules\Sales\Http;

use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Sales\Domain\Models\PaymentAttempt;
use App\Modules\Tenancy\Application\TenantSettingsService;

class OrderPresenter
{
    /**
     * Espera customer, company, currency, items, payment.confirmer, attempts e installments.payment cargados.
     *
     * @return array<string, mixed>
     */
    public static function make(Order $order): array
    {
        $payment = $order->payment;

        return [
            'id' => $order->id,
            'number' => $order->number,
            // Situación de hoy: una pendiente fuera de plazo se muestra vencida
            'status' => $order->state(),
            // Compra personal: quién compró. Venta corporativa: la empresa.
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => trim("{$order->customer->first_name} {$order->customer->last_name}"),
                'email' => $order->customer->email,
            ] : null,
            'company' => $order->company ? ['id' => $order->company->id, 'name' => $order->company->name] : null,
            'currency' => $order->currency->code,
            'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount,
            // Cupón aplicado, si lo hubo
            'coupon_code' => $order->coupon_code,
            // La orden es de un paquete: sus ítems son los cursos que incluye
            'package' => $order->package_id ? ['id' => $order->package_id, 'title' => $order->package_title] : null,
            'total_amount' => $order->total_amount,
            'created_at' => $order->created_at->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'refunded_at' => $order->refunded_at?->toIso8601String(),
            // Venta a empresa cuyas licencias se entregaron antes del pago
            'licenses_released_at' => $order->licenses_released_at?->toIso8601String(),
            'licenses_release_reason' => $order->licenses_release_reason,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'course_id' => $item->course_id,
                'title' => $item->title,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'total_amount' => $item->total_amount,
                'enrolled' => $item->enrollment_id !== null,
            ])->values(),
            'payment' => $payment ? [
                'method' => $payment->method,
                // MANUAL = lo confirmó el personal; si no, la pasarela que lo cobró
                'gateway' => $payment->gateway,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at->toIso8601String(),
                'confirmed_by' => $payment->confirmer ? trim("{$payment->confirmer->first_name} {$payment->confirmer->last_name}") : null,
                // Reembolso total, si lo hubo
                'refund' => $payment->refunded_at ? [
                    'at' => $payment->refunded_at->toIso8601String(),
                    'reason' => $payment->refund_reason,
                    'reference' => $payment->refund_reference,
                    'by' => $payment->relationLoaded('refunder') && $payment->refunder ? trim("{$payment->refunder->first_name} {$payment->refunder->last_name}") : null,
                ] : null,
            ] : null,
            // Cobros que la pasarela hizo y no se aplicaron a la orden: hay que resolverlos a mano
            'payment_reviews' => $order->relationLoaded('attempts')
                ? $order->attempts->where('status', PaymentAttempt::STATUS_NEEDS_REVIEW)->map(fn (PaymentAttempt $attempt) => self::review($attempt))->values()
                : [],
            // Veces que el comprador fue a pagarla a una pasarela: si hay alguna, se le puede volver a preguntar
            'online_attempts' => $order->relationLoaded('attempts') ? $order->attempts->count() : 0,
            ...self::moneyMoved($order),
            ...self::plan($order),
        ];
    }

    /**
     * Un cobro que la pasarela hizo y no se aplicó a la orden.
     *
     * @return array<string, mixed>
     */
    public static function review(PaymentAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'gateway' => $attempt->gateway,
            'payment_id' => $attempt->external_payment_id,
            'reason' => $attempt->review_reason,
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'at' => $attempt->updated_at->toIso8601String(),
        ];
    }

    /**
     * Lo cobrado por la orden que sigue en pie (es lo que devolvería un
     * reembolso) y lo ya devuelto. Cuenta el pago único y el de cada cuota.
     *
     * @return array<string, mixed>
     */
    private static function moneyMoved(Order $order): array
    {
        $payments = collect([$order->payment])
            ->merge($order->relationLoaded('installments') ? $order->installments->map(fn (OrderInstallment $installment) => $installment->relationLoaded('payment') ? $installment->payment : null) : [])
            ->filter();
        $collected = $payments->where('status', Payment::STATUS_CONFIRMED);
        $online = $collected->filter(fn (Payment $payment) => $payment->isOnline());

        return [
            'collected' => [
                'amount' => self::money($collected->sum('amount')),
                'payments' => $collected->count(),
                // Lo que devolvería la pasarela y lo que tiene que devolver la organización por su cuenta
                'online_amount' => self::money($online->sum('amount')),
                'manual_amount' => self::money($collected->sum('amount') - $online->sum('amount')),
                'gateways' => $online->pluck('gateway')->unique()->values(),
            ],
            'refunded_amount' => self::money($payments->where('status', Payment::STATUS_REFUNDED)->sum('amount')),
        ];
    }

    /**
     * Plan de pagos de la orden y lo que debe. Sin cuotas, la orden se paga de una vez.
     *
     * @return array<string, mixed>
     */
    private static function plan(Order $order): array
    {
        if (! $order->relationLoaded('installments') || $order->installments->isEmpty()) {
            return ['installments' => [], 'debt' => null];
        }

        $today = self::today();
        $pending = $order->installments->where('status', OrderInstallment::STATUS_PENDING);
        $overdue = $pending->filter(fn (OrderInstallment $installment) => $installment->isOverdue($today));
        $next = $pending->first();

        return [
            'installments' => $order->installments->map(fn (OrderInstallment $installment) => self::installment($installment, $today))->values(),
            'debt' => [
                'paid_amount' => self::money($order->installments->where('status', OrderInstallment::STATUS_PAID)->sum('amount')),
                'pending_amount' => self::money($pending->sum('amount')),
                'overdue_amount' => self::money($overdue->sum('amount')),
                'overdue_count' => $overdue->count(),
                // La cuota que toca pagar ahora
                'next' => $next ? self::installment($next, $today) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function installment(OrderInstallment $installment, ?string $today = null): array
    {
        $payment = $installment->relationLoaded('payment') ? $installment->payment : null;

        return [
            'id' => $installment->id,
            'number' => $installment->number,
            'amount' => $installment->amount,
            'due_date' => $installment->due_date->toDateString(),
            'status' => $installment->status,
            'overdue' => $installment->isOverdue($today ?? self::today()),
            'paid_at' => $installment->paid_at?->toIso8601String(),
            'payment' => $payment ? [
                'method' => $payment->method,
                'gateway' => $payment->gateway,
                'reference' => $payment->reference,
                'confirmed_by' => $payment->relationLoaded('confirmer') && $payment->confirmer ? trim("{$payment->confirmer->first_name} {$payment->confirmer->last_name}") : null,
                // La cuota se pagó y después se devolvió
                'refund' => $payment->refunded_at ? [
                    'at' => $payment->refunded_at->toIso8601String(),
                    'reason' => $payment->refund_reason,
                    'reference' => $payment->refund_reference,
                ] : null,
            ] : null,
        ];
    }

    /**
     * Día de hoy en la hora de la organización: con él se decide si una cuota ya venció.
     */
    public static function today(): string
    {
        $request = request();

        if (! $request->attributes->has('tenant_today')) {
            $request->attributes->set('tenant_today', now(app(TenantSettingsService::class)->timezone())->toDateString());
        }

        return (string) $request->attributes->get('tenant_today');
    }

    private static function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
