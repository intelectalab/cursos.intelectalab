<?php

namespace App\Modules\B2b\Domain\Models;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Sales\Domain\Models\Order;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class License extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_ASSIGNED = 'ASSIGNED';

    /** Anulada porque se canceló la venta antes de usarla */
    public const STATUS_CANCELLED = 'CANCELLED';

    /** Situación calculada: disponible, pero pasó su plazo para asignarla */
    public const STATE_EXPIRED = 'EXPIRED';

    /** Meses que tiene la empresa para asignar una licencia */
    public const MONTHS_TO_ASSIGN = 12;

    protected $fillable = [
        'tenant_id',
        'corporate_account_id',
        'course_id',
        'order_id',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function state(): string
    {
        return $this->status === self::STATUS_AVAILABLE && $this->expires_at->isPast()
            ? self::STATE_EXPIRED
            : $this->status;
    }

    /**
     * Licencias que hoy se pueden asignar.
     *
     * @param  Builder<License>  $query
     */
    public function scopeAssignable(Builder $query): void
    {
        $query->where('status', self::STATUS_AVAILABLE)->where('expires_at', '>', now());
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class, 'corporate_account_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Asignación vigente (RN-010): a quién, quién la asignó y cuándo.
     */
    public function assignment(): HasOne
    {
        return $this->hasOne(LicenseAssignment::class)->whereNull('revoked_at');
    }
}
