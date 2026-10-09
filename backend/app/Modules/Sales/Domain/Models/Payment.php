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

    public const METHOD_TRANSFER = 'TRANSFER';

    public const METHOD_YAPE = 'YAPE';

    public const METHOD_CASH = 'CASH';

    public const METHOD_OTHER = 'OTHER';

    /** Solo llegan por una pasarela: tarjeta y saldo de la cuenta del comprador en ella */
    public const METHOD_CARD = 'CARD';

    public const METHOD_WALLET = 'WALLET';

    /** Medios que el personal puede registrar al confirmar un pago recibido por fuera */
    public const METHODS = [self::METHOD_TRANSFER, self::METHOD_YAPE, self::METHOD_CASH, self::METHOD_OTHER];

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
