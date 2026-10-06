<?php

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityActivity extends Model
{
    use BelongsToTenant, HasUuids;

    /** Cambio de etapa: lo registra el sistema */
    public const TYPE_STAGE = 'STAGE';

    /** Tipos que registra una persona */
    public const MANUAL_TYPES = ['CALL', 'MEETING', 'EMAIL', 'NOTE'];

    protected $fillable = [
        'tenant_id',
        'opportunity_id',
        'type',
        'notes',
        'occurred_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
