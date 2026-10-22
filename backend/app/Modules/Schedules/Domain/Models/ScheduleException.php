<?php

namespace App\Modules\Schedules\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un día en que el docente no tiene su horario habitual: no hay clase en
 * vivo, no se toma asistencia ni se envía recordatorio.
 */
class ScheduleException extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'teacher_id',
        'date',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }
}
