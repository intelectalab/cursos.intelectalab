<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo global de permisos (no pertenece a un tenant).
 */
class Permission extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'description',
    ];
}
