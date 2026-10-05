<?php

namespace App\Modules\Courses\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Copia del curso tal como se publicó. No se modifica: una corrección
 * es una versión nueva.
 */
class CourseVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'course_id',
        'number',
        'note',
        'snapshot',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
