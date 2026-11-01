<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Crm\Domain\Models\Company;
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

    /** Se paga en cuotas: ya entró al menos una y el comprador tiene acceso; falta el resto */
    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_PAID = 'PAID';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_EXPIRED = 'EXPIRED';

    /** Se pagó y después se devolvió el dinero completo */
    public const STATUS_REFUNDED = 'REFUNDED';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PARTIAL, self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_REFUNDED];

    protected $fillable = [
        'tenant_id',
        'number',
        'customer_id',
        'company_id',
        'package_id',
        'package_title',
        'coupon_code',
        'currency_id',
        'subtotal_amount',
        'discount_amount',
        'total_amount',
        'status',
        'expires_at',
        'paid_at',
        'cancelled_at',
        'cancelled_by',
        'refunded_at',
        'licenses_released_at',
        'licenses_released_by',
        'licenses_release_reason',
        'sold_by',
        'discount_reason',
        'billing',
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
            'refunded_at' => 'datetime',
            'licenses_released_at' => 'datetime',
            'billing' => 'array',
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

    /**
     * Admite un pago: está pendiente y en plazo, o se paga en cuotas y aún faltan.
     */
    public function isPayable(): bool
    {
        return in_array($this->state(), [self::STATUS_PENDING, self::STATUS_PARTIAL], true);
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

    /**
     * Quién del personal registró la venta asistida.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    /**
     * Empresa compradora en una venta corporativa; null en una compra personal.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
        // El pago de la orden completa, también cuando ya se devolvió. Los pagos de cuotas van con su cuota
        return $this->hasOne(Payment::class)->whereNull('installment_id')->whereIn('status', [Payment::STATUS_CONFIRMED, Payment::STATUS_REFUNDED]);
    }

    /**
     * Todo lo cobrado por la orden: su pago único o el de cada cuota, devueltos o no.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Cada vez que el comprador fue a pagarla a una pasarela.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /**
     * Plan de pagos de la orden, en el orden en que se pagan. Vacío = se paga de una vez.
     */
    public function installments(): HasMany
    {
        return $this->hasMany(OrderInstallment::class)->orderBy('number');
    }

    /**
     * Cobros en línea que la pasarela hizo y no se pudieron aplicar a la orden.
     */
    public function attemptsToReview(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->where('status', PaymentAttempt::STATUS_NEEDS_REVIEW);
    }
}
