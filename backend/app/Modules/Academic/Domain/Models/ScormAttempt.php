<?php

namespace App\Modules\Academic\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que un alumno lleva en un paquete SCORM: un solo intento que se retoma.
 */
class ScormAttempt extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'enrollment_id',
        'student_id',
        'course_id',
        'activity_id',
        'package_id',
        'lesson_status',
        'score_percent',
        'cmi',
        'completed_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'score_percent' => 'float',
            'cmi' => 'array',
            'completed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
