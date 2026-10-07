<?php

namespace App\Shared\Idempotency;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Petición con efecto ya atendida (RN-009, RNF-DATA-001).
 */
class IdempotencyKey extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** Horas durante las que una clave repetida devuelve la respuesta guardada */
    public const RETENTION_HOURS = 24;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'key',
        'method',
        'path',
        'request_hash',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
        ];
    }
}
