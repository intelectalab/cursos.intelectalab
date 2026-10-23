<?php

namespace App\Modules\Academic\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Paquete SCORM subido a una actividad. No se modifica: al subir otro se
 * crea uno nuevo, y la versión publicada sigue usando el que publicó.
 */
class ScormPackage extends Model
{
    use BelongsToTenant, HasUuids;

    public const VERSION_12 = '1.2';

    public const VERSION_2004 = '2004';

    protected $fillable = [
        'tenant_id',
        'activity_id',
        'version',
        'entry',
        'title',
        'file_name',
        'size_bytes',
        'files_count',
        'mastery_score',
        'disk',
        'directory',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'files_count' => 'integer',
            'mastery_score' => 'float',
        ];
    }
}
