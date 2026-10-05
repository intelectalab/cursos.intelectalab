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

    public const TYPES = [self::TYPE_QUIZ, self::TYPE_TASK, self::TYPE_SURVEY];

    protected $fillable = [
        'tenant_id',
        'course_id',
        'section_id',
        'type',
        'title',
        'description',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
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
}
