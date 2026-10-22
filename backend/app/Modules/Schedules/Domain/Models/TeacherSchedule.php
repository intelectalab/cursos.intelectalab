<?php

namespace App\Modules\Schedules\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horario semanal de un docente. Cada bloque es un día de la semana
 * (1 = lunes … 7 = domingo) con hora de inicio y fin y un tipo: disponible,
 * clase en vivo (con su curso y enlace) o revisión de trabajos.
 */
class TeacherSchedule extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_OBSERVED = 'OBSERVED';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_OBSERVED];

    public const TYPE_AVAILABLE = 'AVAILABLE';

    public const TYPE_LIVE_CLASS = 'LIVE_CLASS';

    public const TYPE_GRADING = 'GRADING';

    public const TYPES = [self::TYPE_AVAILABLE, self::TYPE_LIVE_CLASS, self::TYPE_GRADING];

    protected $fillable = [
        'tenant_id',
        'teacher_id',
        'valid_from',
        'blocks',
        'status',
        'observation',
        'sent_at',
        'sent_by',
        'responded_at',
        'confirmed_valid_from',
        'confirmed_blocks',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'blocks' => 'array',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'confirmed_valid_from' => 'date:Y-m-d',
            'confirmed_blocks' => 'array',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
