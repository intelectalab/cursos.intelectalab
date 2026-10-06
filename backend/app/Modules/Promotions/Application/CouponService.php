<?php

namespace App\Modules\Promotions\Application;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Promotions\Domain\Models\Coupon;
use App\Modules\Promotions\Domain\Models\CouponRedemption;
use App\Modules\Promotions\Domain\Models\Promotion;
use App\Modules\Sales\Domain\Models\Order;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;

/**
 * Cupones en el checkout (RF-PROMO-001, RN-008): el descuento siempre lo
 * calcula el servidor. Una orden lleva un solo cupón; no se acumulan.
 */
class CouponService
{
    /** Motivos por los que un cupón no aplica, con el texto que ve el comprador */
    private const REASONS = [
        'NOT_FOUND' => 'Ese cupón no existe. Revisa que esté bien escrito.',
        'INACTIVE' => 'Este cupón ya no está disponible.',
        'NOT_STARTED' => 'Este cupón todavía no está vigente.',
        'EXPIRED' => 'Este cupón ya venció.',
        'EXHAUSTED' => 'Este cupón ya se agotó.',
        'ALREADY_USED' => 'Ya usaste esta promoción en otra compra.',
        'NOT_APPLICABLE' => 'Este cupón no aplica a lo que estás comprando.',
        'MIN_PURCHASE' => 'Este cupón requiere una compra mínima mayor.',
        'FREE' => 'No hace falta un cupón: esto ya es gratuito.',
    ];

    /**
     * Evalúa un cupón para una compra y devuelve el descuento que corresponde.
     * Si no aplica, lanza COUPON_INVALID con el motivo (TC-SAL-003).
     *
     * @param  string  $productType  Promotion::PRODUCT_COURSE o PRODUCT_PACKAGE
     * @param  bool  $lock  serializa el conteo de usos; solo dentro de la transacción que crea la orden
     * @return array{coupon: Coupon, promotion: Promotion, discount: string}
     */
    public function evaluate(string $code, User $customer, string $productType, string $productId, string $subtotal, bool $lock = false): array
    {
        $coupon = Coupon::query()->where('code', Coupon::normalize($code))->first();

        if (! $coupon) {
            $this->reject('NOT_FOUND');
        }

        $promotion = Promotion::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($coupon->promotion_id);

        if ($coupon->status !== Promotion::STATUS_ACTIVE || ! $promotion->isActive()) {
            $this->reject('INACTIVE');
        }

        if ($promotion->starts_at?->isFuture()) {
            $this->reject('NOT_STARTED');
        }

        if ($promotion->ends_at?->isPast()) {
            $this->reject('EXPIRED');
        }

        if ((float) $subtotal <= 0) {
            $this->reject('FREE');
        }

        $applies = $promotion->applies_to === Promotion::APPLIES_ALL
            || $promotion->items()->where('product_type', $productType)->where('product_id', $productId)->exists();

        if (! $applies) {
            $this->reject('NOT_APPLICABLE');
        }

        if ($promotion->min_purchase !== null && (float) $subtotal < (float) $promotion->min_purchase) {
            $this->reject('MIN_PURCHASE', ['min_purchase' => $promotion->min_purchase]);
        }

        $used = CouponRedemption::query()->where('promotion_id', $promotion->id)->counting();

        if ($promotion->max_redemptions !== null && (clone $used)->count() >= $promotion->max_redemptions) {
            $this->reject('EXHAUSTED');
        }

        if ($promotion->once_per_customer && (clone $used)->where('customer_id', $customer->id)->exists()) {
            $this->reject('ALREADY_USED');
        }

        return ['coupon' => $coupon, 'promotion' => $promotion, 'discount' => $promotion->discountFor($subtotal)];
    }

    /**
     * Deja registrado el uso del cupón en la orden recién creada.
     *
     * @param  array{coupon: Coupon, promotion: Promotion, discount: string}  $applied
     */
    public function redeem(array $applied, Order $order, User $customer): void
    {
        CouponRedemption::create([
            'coupon_id' => $applied['coupon']->id,
            'promotion_id' => $applied['promotion']->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'discount_amount' => $applied['discount'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function reject(string $reason, array $details = []): never
    {
        throw new ApiException(ErrorCode::CouponInvalid, self::REASONS[$reason], 422, ['reason' => $reason] + $details);
    }
}
