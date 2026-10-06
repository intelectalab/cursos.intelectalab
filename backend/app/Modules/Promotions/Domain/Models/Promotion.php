<?php

namespace App\Modules\Promotions\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_PERCENT = 'PERCENT';

    public const TYPE_FIXED = 'FIXED';

    public const TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED];

    public const APPLIES_ALL = 'ALL';

    public const APPLIES_SELECTED = 'SELECTED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const PRODUCT_COURSE = 'COURSE';

    public const PRODUCT_PACKAGE = 'PACKAGE';

    public const PRODUCT_TYPES = [self::PRODUCT_COURSE, self::PRODUCT_PACKAGE];

    protected $fillable = [
        'tenant_id',
        'name',
        'discount_type',
        'discount_value',
        'starts_at',
        'ends_at',
        'max_redemptions',
        'once_per_customer',
        'min_purchase',
        'applies_to',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_redemptions' => 'integer',
            'once_per_customer' => 'boolean',
            'min_purchase' => 'decimal:2',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Descuento que le corresponde a un importe. Un monto fijo nunca deja el total en negativo.
     */
    public function discountFor(string $subtotal): string
    {
        $amount = $this->discount_type === self::TYPE_PERCENT
            ? round((float) $subtotal * (float) $this->discount_value / 100, 2)
            : min((float) $this->discount_value, (float) $subtotal);

        return number_format($amount, 2, '.', '');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class)->orderBy('created_at')->orderBy('code');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
