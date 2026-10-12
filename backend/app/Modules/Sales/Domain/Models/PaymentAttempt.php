<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada vez que un comprador va a pagar una orden a una pasarela. El pago
 * que cuenta sigue siendo el de payments; esto guarda el camino.
 */
class PaymentAttempt extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_CREATED = 'CREATED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    /** La pasarela cobró, pero el pago no se pudo aplicar a la orden: lo resuelve una persona */
    public const STATUS_NEEDS_REVIEW = 'NEEDS_REVIEW';

    /** Estuvo en revisión y una persona ya lo resolvió (devolvió el cobro o lo aplicó a mano) */
    public const STATUS_RESOLVED = 'RESOLVED';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'gateway',
        'external_id',
        'external_payment_id',
        'status',
        'amount',
        'currency',
        'exchange_rate',
        'checkout_url',
        'account_ref',
        'account_name',
        'review_reason',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
