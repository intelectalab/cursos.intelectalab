<?php

namespace App\Modules\Academic\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'activity_id',
        'passing_percent',
        'max_attempts',
        'time_limit_minutes',
        'shuffle',
    ];

    protected function casts(): array
    {
        return [
            'passing_percent' => 'float',
            'max_attempts' => 'integer',
            'time_limit_minutes' => 'integer',
            'shuffle' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position');
    }
}
