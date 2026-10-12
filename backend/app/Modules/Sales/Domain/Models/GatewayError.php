<?php

namespace App\Modules\Sales\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Algo que una pasarela rechazó o no respondió. No guarda claves ni datos
 * de quien paga: solo qué se intentaba, qué dijo la pasarela y qué se le
 * explicó a la persona.
 */
class GatewayError extends Model
{
    use BelongsToTenant, HasUuids;

    /** Con la cuenta de la organización: cobro a sus alumnos */
    public const SCOPE_TENANT = 'TENANT';

    /** Con la cuenta de la plataforma: cobro de los planes */
    public const SCOPE_PLATFORM = 'PLATFORM';

    public const OPERATION_CONNECT = 'CONNECT';

    public const OPERATION_CHECKOUT = 'CHECKOUT';

    public const OPERATION_SYNC = 'SYNC';

    public const OPERATION_REFUND = 'REFUND';

    public const OPERATION_WEBHOOK = 'WEBHOOK';

    protected $table = 'payment_gateway_errors';

    protected $fillable = [
        'tenant_id',
        'scope',
        'gateway',
        'operation',
        'subject',
        'message',
        'gateway_status',
        'gateway_reason',
        'request_id',
        'occurrences',
        'last_at',
    ];

    protected function casts(): array
    {
        return [
            'gateway_status' => 'integer',
            'occurrences' => 'integer',
            'last_at' => 'datetime',
        ];
    }
}
