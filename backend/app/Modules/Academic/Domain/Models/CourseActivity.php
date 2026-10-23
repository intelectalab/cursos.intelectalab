<?php

namespace App\Modules\Academic\Domain\Models;

use App\Modules\Courses\Domain\Models\Course;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourseActivity extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_QUIZ = 'QUIZ';

    public const TYPE_TASK = 'TASK';

    public const TYPE_SURVEY = 'SURVEY';

    // Paquete SCORM (RF-SCORM-001)
    public const TYPE_SCORM = 'SCORM';

    public const TYPES = [self::TYPE_QUIZ, self::TYPE_TASK, self::TYPE_SURVEY, self::TYPE_SCORM];

    protected $fillable = [
        'tenant_id',
        'course_id',
        'section_id',
        'type',
        'title',
        'description',
        'position',
        'weight',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'weight' => 'float',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class, 'activity_id');
    }

    public function task(): HasOne
    {
        return $this->hasOne(Task::class, 'activity_id');
    }

    public function survey(): HasOne
    {
        return $this->hasOne(Survey::class, 'activity_id');
    }

    /** El último paquete SCORM subido (el que se publica) */
    public function scormPackage(): HasOne
    {
        // Sin ofMany: PostgreSQL no calcula MAX de un uuid
        return $this->hasOne(ScormPackage::class, 'activity_id')->orderByDesc('created_at')->orderByDesc('id');
    }
}
