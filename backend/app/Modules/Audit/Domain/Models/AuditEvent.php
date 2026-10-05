<?php

namespace App\Modules\Audit\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de auditoría: solo inserción (la BD rechaza UPDATE y DELETE).
 */
class AuditEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
