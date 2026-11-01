<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un dispositivo en el que una persona activó los avisos al teléfono.
 */
class PushSubscription extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'user_agent',
        'last_used_at',
    ];

    protected $hidden = ['endpoint', 'endpoint_hash', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
