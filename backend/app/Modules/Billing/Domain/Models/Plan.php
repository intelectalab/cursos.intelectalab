<?php

namespace App\Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Plan que la plataforma ofrece a las organizaciones. Es de la plataforma:
 * no pertenece a ningún tenant.
 */
class Plan extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    /** Periodos de pago admitidos, en meses */
    public const BILLING_MONTHS = [1, 12];

    protected $fillable = [
        'name',
        'description',
        'price',
        'currency',
        'billing_months',
        'max_students',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billing_months' => 'integer',
            'max_students' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
