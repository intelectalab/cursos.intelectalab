<?php

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_NEW = 'NEW';

    public const STATUS_CONTACTED = 'CONTACTED';

    public const STATUS_CONVERTED = 'CONVERTED';

    public const STATUS_DISCARDED = 'DISCARDED';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_CONVERTED, self::STATUS_DISCARDED];

    /** Estados en los que todavía se trabaja el prospecto */
    public const OPEN_STATUSES = [self::STATUS_NEW, self::STATUS_CONTACTED];

    public const SOURCES = ['WEB', 'REFERRAL', 'EVENT', 'CALL', 'SOCIAL', 'OTHER'];

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'company_name',
        'position',
        'email',
        'phone',
        'source',
        'interest',
        'status',
        'discard_reason',
        'owner_id',
        'created_by',
        'converted_at',
        'converted_by',
        'company_id',
        'contact_id',
        'opportunity_id',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
