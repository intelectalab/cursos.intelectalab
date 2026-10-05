<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GradeScale extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'max_score',
    ];

    protected function casts(): array
    {
        return [
            'max_score' => 'integer',
        ];
    }
}
