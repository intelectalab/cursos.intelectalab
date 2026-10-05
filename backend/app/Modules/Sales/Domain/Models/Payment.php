<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_CONFIRMED = 'CONFIRMED';

    /** Medios con los que el comprador puede pagar mientras no haya pasarela (PD-001, PD-002) */
    public const METHODS = ['TRANSFER', 'YAPE', 'CASH', 'OTHER'];

    protected $fillable = [
        'tenant_id',
        'order_id',
        'currency_id',
        'amount',
        'method',
        'gateway',
        'status',
        'reference',
        'notes',
        'confirmed_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
