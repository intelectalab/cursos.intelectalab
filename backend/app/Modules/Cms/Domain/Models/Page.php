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

    /** El borrador espera a quien publica */
    public const REVIEW_PENDING = 'IN_REVIEW';

    /** Quien publica lo devolvió con observaciones */
    public const REVIEW_CHANGES_REQUESTED = 'CHANGES_REQUESTED';

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
        'review_status',
        'review_requested_by',
        'review_requested_at',
        'review_comment',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'show_in_footer' => 'boolean',
            'published_at' => 'datetime',
            'draft_updated_at' => 'datetime',
            'review_requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    /**
     * Hay algo en el borrador que el público no ve: nunca se publicó o cambió después.
     */
    public function hasUnpublishedDraft(): bool
    {
        return ! $this->isPublished() || $this->hasPendingChanges();
    }

    public function reviewRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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
