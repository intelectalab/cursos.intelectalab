<?php

namespace App\Modules\Certificates\Domain\Models;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ISSUED = 'ISSUED';

    public const STATUS_REVOKED = 'REVOKED';

    public const STATUSES = [self::STATUS_ISSUED, self::STATUS_REVOKED];

    protected $fillable = [
        'tenant_id',
        'enrollment_id',
        'course_id',
        'student_id',
        'verification_code',
        'status',
        'snapshot',
        'issued_at',
        'issued_by',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
