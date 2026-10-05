<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PAID = 'PAID';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_EXPIRED];

    protected $fillable = [
        'tenant_id',
        'number',
        'customer_id',
        'currency_id',
        'subtotal_amount',
        'discount_amount',
        'total_amount',
        'status',
        'expires_at',
        'paid_at',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Situación de la orden hoy: una pendiente cuyo plazo pasó cuenta como
     * vencida aunque la tarea programada aún no la haya marcado.
     */
    public function state(): string
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at?->isPast()
            ? self::STATUS_EXPIRED
            : $this->status;
    }

    public function isPayable(): bool
    {
        return $this->state() === self::STATUS_PENDING;
    }

    /**
     * Filtra por la situación de hoy (ver state()).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeInState(Builder $query, string $state): void
    {
        $now = now();

        match ($state) {
            self::STATUS_PENDING => $query->where('status', self::STATUS_PENDING)
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now)),
            self::STATUS_EXPIRED => $query->where(fn (Builder $q) => $q
                ->where('status', self::STATUS_EXPIRED)
                ->orWhere(fn (Builder $overdue) => $overdue->where('status', self::STATUS_PENDING)->where('expires_at', '<', $now))),
            default => $query->where('status', $state),
        };
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->where('status', Payment::STATUS_CONFIRMED);
    }
}
