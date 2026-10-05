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
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'grade_percent' => 'float',
            'graded_at' => 'datetime',
        ];
    }

    public function isGraded(): bool
    {
        return $this->status === self::STATUS_GRADED;
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
