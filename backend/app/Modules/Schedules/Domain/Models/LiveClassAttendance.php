<?php

namespace App\Modules\Schedules\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Si un alumno estuvo en una clase en vivo de su curso.
 */
class LiveClassAttendance extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_PRESENT = 'PRESENT';

    public const STATUS_LATE = 'LATE';

    public const STATUS_ABSENT = 'ABSENT';

    /** Faltó con justificación: no cuenta para el porcentaje */
    public const STATUS_EXCUSED = 'EXCUSED';

    public const STATUSES = [self::STATUS_PRESENT, self::STATUS_LATE, self::STATUS_ABSENT, self::STATUS_EXCUSED];

    protected $fillable = [
        'tenant_id',
        'course_id',
        'session_date',
        'session_start',
        'student_id',
        'status',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date:Y-m-d',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
