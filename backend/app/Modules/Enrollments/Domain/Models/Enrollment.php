<?php

namespace App\Modules\Enrollments\Domain\Models;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const SOURCE_MANUAL = 'MANUAL';

    protected $fillable = [
        'tenant_id',
        'course_id',
        'student_id',
        'status',
        'source',
        'enrolled_by',
        'enrolled_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
