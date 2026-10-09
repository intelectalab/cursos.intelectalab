<?php

namespace App\Modules\Billing\Domain\Models;

use App\Modules\Files\Domain\Models\File;
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

    /** Pasó su plazo sin que la organización pagara ni informara un pago */
    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_EXPIRED];

    /** Medios con los que la plataforma registra haber recibido el pago */
    public const METHODS = ['TRANSFER', 'YAPE', 'CASH', 'OTHER'];

    /** Uso de archivo de la constancia de pago (config/files.php) */
    public const PROOF_PURPOSE = 'plan_payment_proof';

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
        'proof_file_id',
        'payment_method',
        'payment_reference',
        'notes',
        'resolved_by',
        'paid_at',
        'cancelled_at',
        'expires_at',
        'previous_subscription',
        'applied_period_ends_at',
        'outcome_seen_at',
        'gateway',
        'gateway_checkout_id',
        'gateway_payment_id',
        'gateway_amount',
        'gateway_currency',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'billing_months' => 'integer',
            'max_students' => 'integer',
            'amount' => 'decimal:2',
            'gateway_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'applied_period_ends_at' => 'datetime',
            'outcome_seen_at' => 'datetime',
            'previous_subscription' => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * La organización dijo que ya pagó: escribió cómo ubicar su pago o adjuntó la constancia.
     */
    public function isInformed(): bool
    {
        return filled($this->payer_reference) || $this->proof_file_id !== null;
    }

    /**
     * La cobró una pasarela, no la confirmó una persona.
     */
    public function wasPaidOnline(): bool
    {
        return $this->status === self::STATUS_PAID && $this->gateway_payment_id !== null;
    }

    public function code(): string
    {
        return 'P-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function proof(): BelongsTo
    {
        return $this->belongsTo(File::class, 'proof_file_id');
    }
}
