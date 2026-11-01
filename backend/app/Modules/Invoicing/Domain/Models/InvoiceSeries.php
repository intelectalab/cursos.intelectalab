<?php

namespace App\Modules\Invoicing\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * El último número usado de una serie de comprobantes.
 */
class InvoiceSeries extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'document_type', 'series', 'last_number'];

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}
