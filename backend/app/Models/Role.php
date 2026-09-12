<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasUuids, BelongsToTenant;

    protected $table = 'erp.role';
    protected $primaryKey = 'role_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // La tabla no tiene created_at/updated_at

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'status',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'erp.role_permission',
            'role_id',
            'permission_id'
        );
    }
}