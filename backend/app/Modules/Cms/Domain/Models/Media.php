<?php

namespace App\Modules\Cms\Domain\Models;

use App\Modules\Files\Domain\Models\File;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use BelongsToTenant, HasUuids;

    /** Uso de archivos (config/files.php) */
    public const FILE_PURPOSE = 'page_media';

    protected $table = 'media';

    protected $fillable = [
        'tenant_id',
        'file_id',
        'uploaded_by',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
