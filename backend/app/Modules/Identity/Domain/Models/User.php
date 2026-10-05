<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasUuids, Notifiable;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    /** Tipos de documento y la validación de su número */
    public const DOCUMENT_TYPES = [
        'DNI' => ['label' => 'DNI', 'rule' => 'digits:8'],
        'CE' => ['label' => 'Carné de extranjería', 'rule' => 'regex:/^[A-Za-z0-9]{6,12}$/'],
        'PASAPORTE' => ['label' => 'Pasaporte', 'rule' => 'regex:/^[A-Za-z0-9]{6,12}$/'],
    ];

    protected $fillable = [
        'tenant_id',
        'email',
        'password',
        'first_name',
        'last_name',
        'phone',
        'doc_type',
        'doc_number',
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

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /**
     * Códigos de permiso otorgados por los roles del usuario (incluye comodines).
     *
     * @return Collection<int, string>
     */
    public function permissionCodes(): Collection
    {
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
            ->unique()
            ->values();
    }

    public function hasPermission(string $required): bool
    {
        return PermissionCatalog::covers($this->permissionCodes(), $required);
    }
}
