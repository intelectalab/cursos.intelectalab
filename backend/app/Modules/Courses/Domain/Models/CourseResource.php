<?php

namespace App\Modules\Courses\Domain\Models;

use App\Modules\Files\Domain\Models\File;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseResource extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_VIDEO = 'VIDEO';

    public const TYPE_DOCUMENT = 'DOCUMENT';

    public const TYPE_LINK = 'LINK';

    public const TYPE_TEXT = 'TEXT';

    /** El video es un enlace a YouTube; no se sube al sistema */
    public const TYPES = [self::TYPE_VIDEO, self::TYPE_DOCUMENT, self::TYPE_LINK, self::TYPE_TEXT];

    /** Uso declarado en config/files.php para los materiales */
    public const FILE_PURPOSE = 'course_material';

    protected $fillable = [
        'tenant_id',
        'course_id',
        'section_id',
        'type',
        'title',
        'position',
        'url',
        'body',
        'file_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
