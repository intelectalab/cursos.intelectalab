<?php

namespace App\Modules\Identity\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Lista de personas con nombre ("Promoción 2026-II", "Área de ventas") que se
 * arma una vez y se reutiliza, por ejemplo para matricularlas juntas.
 */
class Group extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'created_by',
    ];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')->using(GroupMember::class);
    }
}
