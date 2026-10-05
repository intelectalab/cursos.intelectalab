<?php

namespace App\Modules\Academic\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_EXPIRED = 'EXPIRED';

    protected $fillable = [
        'tenant_id',
        'enrollment_id',
        'student_id',
        'course_id',
        'course_version_id',
        'activity_id',
        'number',
        'status',
        'questions',
        'passing_percent',
        'started_at',
        'expires_at',
        'submitted_at',
        'score_percent',
        'passed',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'questions' => 'array',
            'passing_percent' => 'float',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score_percent' => 'float',
            'passed' => 'boolean',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class, 'attempt_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
