<?php

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    use BelongsToTenant, HasUuids;

    public const STAGE_NEW = 'NEW';

    public const STAGE_CONTACTED = 'CONTACTED';

    public const STAGE_PROPOSAL = 'PROPOSAL';

    public const STAGE_NEGOTIATION = 'NEGOTIATION';

    public const STAGE_WON = 'WON';

    public const STAGE_LOST = 'LOST';

    /** Etapas en el orden del embudo; las dos últimas cierran la oportunidad */
    public const STAGES = [
        self::STAGE_NEW => 'Nueva',
        self::STAGE_CONTACTED => 'Contactada',
        self::STAGE_PROPOSAL => 'Propuesta enviada',
        self::STAGE_NEGOTIATION => 'Negociación',
        self::STAGE_WON => 'Ganada',
        self::STAGE_LOST => 'Perdida',
    ];

    public const CLOSED_STAGES = [self::STAGE_WON, self::STAGE_LOST];

    protected $fillable = [
        'tenant_id',
        'company_id',
        'contact_id',
        'title',
        'stage',
        'estimated_amount',
        'expected_close_date',
        'notes',
        'lost_reason',
        'closed_at',
        'owner_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'expected_close_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return in_array($this->stage, self::CLOSED_STAGES, true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(OpportunityActivity::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
