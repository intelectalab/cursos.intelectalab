<?php

namespace App\Modules\Billing\Domain\Models;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden con la que una organización le paga un plan a la plataforma. Se
 * consulta siempre por tenant_id; el aislamiento lo refuerza la base (RLS).
 */
class PlanOrder extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PAID = 'PAID';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_CANCELLED];

    /** Medios con los que la plataforma registra haber recibido el pago */
    public const METHODS = ['TRANSFER', 'YAPE', 'CASH', 'OTHER'];

    protected $fillable = [
        'tenant_id',
        'number',
        'plan_id',
        'plan_name',
        'billing_months',
        'max_students',
        'amount',
        'currency',
        'status',
        'requested_by',
        'payer_reference',
        'payment_method',
        'payment_reference',
        'notes',
        'resolved_by',
        'paid_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'billing_months' => 'integer',
            'max_students' => 'integer',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
