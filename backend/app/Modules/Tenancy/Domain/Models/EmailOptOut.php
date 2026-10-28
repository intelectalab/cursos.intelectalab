<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Una persona dejó de recibir un tipo de correo de la academia.
 */
class EmailOptOut extends Model
{
    use BelongsToTenant, HasUuids;

    public const SOURCE_LINK = 'LINK';

    public const SOURCE_ONE_CLICK = 'ONE_CLICK';

    public const SOURCE_ACCOUNT = 'ACCOUNT';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'email',
        'category',
        'source',
    ];
}
