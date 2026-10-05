<?php

namespace App\Modules\Certificates\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CertificateVerification extends Model
{
    use BelongsToTenant, HasUuids;

    public const RESULT_VALID = 'VALID';

    public const RESULT_REVOKED = 'REVOKED';

    public const RESULT_NOT_FOUND = 'NOT_FOUND';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'certificate_id',
        'code',
        'result',
        'ip_address',
        'user_agent',
    ];
}
