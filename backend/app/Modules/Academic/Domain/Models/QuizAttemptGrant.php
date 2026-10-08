<?php

namespace App\Modules\Academic\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intentos adicionales que quien revisa concede a un alumno en un cuestionario.
 */
class QuizAttemptGrant extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'enrollment_id',
        'course_id',
        'activity_id',
        'attempts',
        'reason',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
        ];
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
