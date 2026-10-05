<?php

namespace App\Modules\Academic\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'activity_id',
        'allow_text',
        'allow_file',
    ];

    protected function casts(): array
    {
        return [
            'allow_text' => 'boolean',
            'allow_file' => 'boolean',
        ];
    }
}
