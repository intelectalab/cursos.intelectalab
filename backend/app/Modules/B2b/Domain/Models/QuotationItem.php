<?php

namespace App\Modules\B2b\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'quotation_id',
        'course_id',
        'title',
        'quantity',
        'list_price',
        'unit_price',
        'total_amount',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'list_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'position' => 'integer',
        ];
    }
}
