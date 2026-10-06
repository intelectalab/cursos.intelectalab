<?php

namespace App\Modules\B2b\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Copia de una cotización tal como se envió al cliente (RN-011).
 */
class QuotationVersion extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'quotation_id',
        'version',
        'snapshot',
        'total_amount',
        'sent_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'total_amount' => 'decimal:2',
            'sent_at' => 'datetime',
        ];
    }
}
