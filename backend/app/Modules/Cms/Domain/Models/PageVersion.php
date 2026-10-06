<?php

namespace App\Modules\Cms\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Copia de una página tal como se publicó.
 */
class PageVersion extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'page_id',
        'version',
        'title',
        'content',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'content' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
