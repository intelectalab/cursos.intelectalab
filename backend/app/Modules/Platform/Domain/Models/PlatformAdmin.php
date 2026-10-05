<?php

namespace App\Modules\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Administrador de la plataforma SaaS. Identidad separada de los usuarios de
 * las organizaciones: no pertenece a ningún tenant y solo opera en el dominio
 * central. Ningún rol de tenant (ni "*") alcanza sus operaciones.
 */
class PlatformAdmin extends Authenticatable
{
    use HasApiTokens, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
