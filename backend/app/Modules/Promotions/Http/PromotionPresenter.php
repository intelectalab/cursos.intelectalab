<?php

namespace App\Modules\Promotions\Http;

use App\Modules\Promotions\Domain\Models\Coupon;
use App\Modules\Promotions\Domain\Models\CouponRedemption;
use App\Modules\Promotions\Domain\Models\Promotion;
use App\Modules\Promotions\Domain\Models\PromotionItem;

class PromotionPresenter
{
    /**
     * Espera items y coupons cargados, y el conteo uses_count (usos que cuentan).
     *
     * @param  array<string, int>  $usesByCoupon  usos que cuentan por cupón
     * @return array<string, mixed>
     */
    public static function make(Promotion $promotion, array $usesByCoupon = [], ?string $timezone = null): array
    {
        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'discount_type' => $promotion->discount_type,
            'discount_value' => $promotion->discount_value,
            'starts_at' => $promotion->starts_at?->toIso8601String(),
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            // Los mismos límites como días de la organización, que es como se eligen y se muestran
            'starts_on' => $promotion->starts_at?->copy()->setTimezone($timezone ?? config('app.timezone'))->toDateString(),
            'ends_on' => $promotion->ends_at?->copy()->setTimezone($timezone ?? config('app.timezone'))->toDateString(),
            'max_redemptions' => $promotion->max_redemptions,
            'once_per_customer' => $promotion->once_per_customer,
            'min_purchase' => $promotion->min_purchase,
            'applies_to' => $promotion->applies_to,
            'status' => $promotion->status,
            // Situación de hoy: activa, por empezar, vencida, agotada o desactivada
            'state' => self::state($promotion),
            'uses' => (int) ($promotion->uses_count ?? 0),
            'items' => $promotion->items->map(fn (PromotionItem $item) => ['type' => $item->product_type, 'id' => $item->product_id])->values(),
            'coupons' => $promotion->coupons->map(fn (Coupon $coupon) => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'label' => $coupon->label,
                'status' => $coupon->status,
                'uses' => $usesByCoupon[$coupon->id] ?? 0,
            ])->values(),
        ];
    }

    /**
     * Espera order.currency y customer cargados.
     *
     * @return array<string, mixed>
     */
    public static function redemption(CouponRedemption $redemption): array
    {
        return [
            'id' => $redemption->id,
            'coupon_code' => $redemption->coupon->code,
            'order' => ['id' => $redemption->order->id, 'number' => $redemption->order->number, 'status' => $redemption->order->state()],
            'customer' => trim("{$redemption->customer?->first_name} {$redemption->customer?->last_name}"),
            'currency' => $redemption->order->currency->code,
            'discount_amount' => $redemption->discount_amount,
            'total_amount' => $redemption->order->total_amount,
            'created_at' => $redemption->created_at->toIso8601String(),
        ];
    }

    private static function state(Promotion $promotion): string
    {
        return match (true) {
            ! $promotion->isActive() => 'INACTIVE',
            (bool) $promotion->starts_at?->isFuture() => 'SCHEDULED',
            (bool) $promotion->ends_at?->isPast() => 'EXPIRED',
            $promotion->max_redemptions !== null && (int) ($promotion->uses_count ?? 0) >= $promotion->max_redemptions => 'EXHAUSTED',
            default => 'ACTIVE',
        };
    }
}
