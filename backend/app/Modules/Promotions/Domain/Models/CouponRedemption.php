<?php

namespace App\Modules\Promotions\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'coupon_id',
        'promotion_id',
        'order_id',
        'customer_id',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
        ];
    }

    /**
     * Usos que cuentan: los de órdenes pagadas o que todavía esperan su pago.
     * Si la orden vence o se cancela, el uso queda libre sin tocar nada aquí.
     *
     * @param  Builder<CouponRedemption>  $query
     */
    public function scopeCounting(Builder $query): void
    {
        $query->whereHas('order', fn (Builder $order) => $order->where(fn (Builder $state) => $state
            ->where('status', Order::STATUS_PAID)
            ->orWhere(fn (Builder $pending) => $pending
                ->where('status', Order::STATUS_PENDING)
                ->where(fn (Builder $deadline) => $deadline->whereNull('expires_at')->orWhere('expires_at', '>=', now())))));
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
