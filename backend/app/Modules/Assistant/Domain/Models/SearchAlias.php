<?php

namespace App\Modules\Assistant\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sinónimo aprendido por el buscador del asistente: alguien buscó un término
 * y eligió un curso o paquete cuyo nombre no lo contiene.
 */
class SearchAlias extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_COURSE = 'COURSE';

    public const TYPE_PACKAGE = 'PACKAGE';

    public const TYPES = [self::TYPE_COURSE, self::TYPE_PACKAGE];

    protected $fillable = [
        'tenant_id',
        'term',
        'target_type',
        'target_id',
        'hits',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
        ];
    }
}
