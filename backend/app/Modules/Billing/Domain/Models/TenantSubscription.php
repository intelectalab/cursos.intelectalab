<?php

namespace App\Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suscripción vigente de una organización: una fila por organización. Se
 * consulta siempre por tenant_id; el aislamiento lo refuerza la base (RLS).
 */
class TenantSubscription extends Model
{
    use HasUuids;

    public const STATUS_TRIAL = 'TRIAL';

    public const STATUS_ACTIVE = 'ACTIVE';

    /** Situación de hoy, deducida del estado y las fechas */
    public const STATE_TRIAL = 'TRIAL';

    public const STATE_TRIAL_EXPIRED = 'TRIAL_EXPIRED';

    public const STATE_ACTIVE = 'ACTIVE';

    public const STATE_EXPIRED = 'EXPIRED';

    /** Sin plan y sin vencimiento: organización anterior a los planes o de cortesía */
    public const STATE_UNLIMITED = 'UNLIMITED';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'current_period_ends_at',
        'max_students',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'max_students' => 'integer',
        ];
    }

    public function state(): string
    {
        if ($this->status === self::STATUS_TRIAL) {
            return $this->trial_ends_at?->isPast() ? self::STATE_TRIAL_EXPIRED : self::STATE_TRIAL;
        }

        if ($this->plan_id === null) {
            return self::STATE_UNLIMITED;
        }

        return $this->current_period_ends_at?->isPast() ? self::STATE_EXPIRED : self::STATE_ACTIVE;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
