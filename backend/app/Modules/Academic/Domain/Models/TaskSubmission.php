<?php

namespace App\Modules\Academic\Domain\Models;

use App\Modules\Files\Domain\Models\File;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSubmission extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_GRADED = 'GRADED';

    /** Devuelta para que el alumno la rehaga */
    public const STATUS_RETURNED = 'RETURNED';

    public const STATUSES = [self::STATUS_SUBMITTED, self::STATUS_GRADED, self::STATUS_RETURNED];

    /** Uso declarado en config/files.php para los archivos entregados */
    public const FILE_PURPOSE = 'task_submission';

    protected $fillable = [
        'tenant_id',
        'enrollment_id',
        'student_id',
        'course_id',
        'activity_id',
        'body',
        'file_id',
        'status',
        'submitted_at',
        'grade_percent',
        'feedback',
        'graded_by',
        'graded_at',
        'is_late',
        'returned_at',
        'returned_by',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'grade_percent' => 'float',
            'graded_at' => 'datetime',
            'is_late' => 'boolean',
            'returned_at' => 'datetime',
        ];
    }

    public function isGraded(): bool
    {
        return $this->status === self::STATUS_GRADED;
    }

    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
