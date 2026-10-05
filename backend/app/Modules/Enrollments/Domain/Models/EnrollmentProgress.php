<?php

namespace App\Modules\Enrollments\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EnrollmentProgress extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_RESOURCE = 'RESOURCE';

    public const TYPE_ACTIVITY = 'ACTIVITY';

    public $timestamps = false;

    protected $table = 'enrollment_progress';

    protected $fillable = [
        'tenant_id',
        'enrollment_id',
        'item_type',
        'item_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
