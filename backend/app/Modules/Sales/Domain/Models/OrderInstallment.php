<?php

namespace App\Modules\Sales\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una cuota de una orden que se paga por partes. Pagada la primera, el
 * comprador ya tiene acceso; pagada la última, la orden queda pagada.
 */
class OrderInstallment extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PAID = 'PAID';

    /** Cuotas que admite un plan de pagos */
    public const MIN = 2;

    public const MAX = 12;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'number',
        'amount',
        'due_date',
        'status',
        'paid_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Sin pagar y con su fecha ya pasada: es una deuda vencida.
     *
     * @param  string  $today  día de hoy en la hora de la organización (AAAA-MM-DD)
     */
    public function isOverdue(string $today): bool
    {
        return ! $this->isPaid() && $this->due_date->toDateString() < $today;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): HasOne
    {
        // El pago de la cuota, también cuando ya se devolvió
        return $this->hasOne(Payment::class, 'installment_id')->whereIn('status', [Payment::STATUS_CONFIRMED, Payment::STATUS_REFUNDED]);
    }
}
