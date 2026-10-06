<?php

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_PROSPECT = 'PROSPECT';

    public const STATUS_CUSTOMER = 'CUSTOMER';

    public const STATUSES = [self::STATUS_PROSPECT, self::STATUS_CUSTOMER];

    protected $fillable = [
        'tenant_id',
        'name',
        'tax_id',
        'industry',
        'website',
        'email',
        'phone',
        'address',
        'city',
        'notes',
        'status',
        'owner_id',
        'created_by',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderByDesc('is_primary')->orderBy('first_name');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
