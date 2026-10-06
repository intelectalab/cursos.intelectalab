<?php

namespace App\Modules\Cms\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    /** Direcciones que no puede tomar una página */
    public const RESERVED_SLUGS = ['admin', 'api', 'login', 'nueva', 'new'];

    protected $fillable = [
        'tenant_id',
        'slug',
        'title',
        'content',
        'show_in_footer',
        'status',
        'current_version_id',
        'published_at',
        'draft_updated_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'show_in_footer' => 'boolean',
            'published_at' => 'datetime',
            'draft_updated_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * El borrador cambió después de la última publicación: el público todavía ve lo anterior.
     */
    public function hasPendingChanges(): bool
    {
        return $this->isPublished() && $this->draft_updated_at->gt($this->published_at);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class)->orderByDesc('version');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
