<?php

namespace App\Modules\Tenancy\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Texto de la plataforma para un correo: uno propio (pagos de plan) o el
 * texto base de un correo de las academias.
 */
class PlatformEmailTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'subject',
        'body',
        'button',
        'enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
