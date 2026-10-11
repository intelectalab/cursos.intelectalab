<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un valor que tuvo el tipo de cambio de la organización. No se edita: cada
 * cambio es un registro nuevo, y vale el más reciente.
 */
class ExchangeRate extends Model
{
    use BelongsToTenant, HasUuids;

    /** Lo escribió una persona */
    public const SOURCE_MANUAL = 'MANUAL';

    /** Tipo de cambio oficial publicado por SUNAT */
    public const SOURCE_SUNAT = 'SUNAT';

    protected $fillable = [
        'tenant_id',
        'base_currency',
        'quote_currency',
        'rate',
        'source',
        'official_rate',
        'margin_percent',
        'effective_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'official_rate' => 'float',
            'margin_percent' => 'float',
            'effective_date' => 'date',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
