<?php

namespace App\Modules\Sales\Http;

use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;

class OrderPresenter
{
    /**
     * Espera customer, currency, items y payment.confirmer cargados.
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
            'customer' => [
                'id' => $order->customer->id,
                'name' => trim("{$order->customer->first_name} {$order->customer->last_name}"),
                'email' => $order->customer->email,
            ],
            'currency' => $order->currency->code,
            'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount,
            'total_amount' => $order->total_amount,
            'created_at' => $order->created_at->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
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
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at->toIso8601String(),
                'confirmed_by' => $payment->confirmer ? trim("{$payment->confirmer->first_name} {$payment->confirmer->last_name}") : null,
            ] : null,
        ];
    }
}
