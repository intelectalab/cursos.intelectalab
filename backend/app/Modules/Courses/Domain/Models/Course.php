<?php

namespace App\Modules\Courses\Domain\Models;

use App\Modules\Files\Domain\Models\File;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    public const LEVELS = ['BASIC', 'INTERMEDIATE', 'ADVANCED'];

    public const LANGUAGES = ['es', 'en', 'pt'];

    protected $fillable = [
        'tenant_id',
        'title',
        'summary',
        'description',
        'category',
        'level',
        'duration_hours',
        'language',
        'cover_file_id',
        'status',
        'current_version_id',
        'has_pending_changes',
        'published_at',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_hours' => 'integer',
            'has_pending_changes' => 'boolean',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('position');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(CourseResource::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class)->orderByDesc('number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'current_version_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(File::class, 'cover_file_id');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_teachers')->using(CourseTeacher::class);
    }
}
