<?php

namespace App\Modules\B2b\Domain\Models;

use App\Modules\Crm\Domain\Models\Company;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La empresa como cliente: de aquí cuelgan sus licencias y las personas de su portal.
 */
class CorporateAccount extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'company_id',
    ];

    public static function forCompany(string $companyId): self
    {
        return self::query()->firstOrCreate(['company_id' => $companyId]);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
