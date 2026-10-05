<?php

namespace App\Modules\Certificates\Domain\Models;

use App\Modules\Files\Domain\Models\File;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateTemplate extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'signer_name',
        'signer_title',
        'signature_file_id',
    ];

    public function signature(): BelongsTo
    {
        return $this->belongsTo(File::class, 'signature_file_id');
    }
}
