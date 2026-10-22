<?php

namespace App\Modules\Courses\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reseña de un alumno sobre un curso (YC-CUR-008): de 1 a 5 estrellas y un
 * comentario. Se publica al momento; la academia puede ocultarla.
 */
class CourseReview extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_VISIBLE = 'VISIBLE';

    public const STATUS_HIDDEN = 'HIDDEN';

    protected $fillable = [
        'tenant_id',
        'course_id',
        'student_id',
        'rating',
        'comment',
        'status',
        'hidden_reason',
        'hidden_by',
        'hidden_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'hidden_at' => 'datetime',
        ];
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
