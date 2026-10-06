<?php

namespace App\Modules\Claims\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Claim extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_CLAIM = 'RECLAMO';

    public const TYPE_COMPLAINT = 'QUEJA';

    public const TYPES = [self::TYPE_CLAIM, self::TYPE_COMPLAINT];

    public const GOOD_TYPES = ['PRODUCT', 'SERVICE'];

    public const DOC_TYPES = ['DNI', 'CE', 'PASAPORTE', 'RUC'];

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_IN_REVIEW = 'IN_REVIEW';

    public const STATUS_ANSWERED = 'ANSWERED';

    public const STATUSES = [self::STATUS_RECEIVED, self::STATUS_IN_REVIEW, self::STATUS_ANSWERED];

    protected $fillable = [
        'tenant_id',
        'year',
        'number',
        'code',
        'type',
        'first_name',
        'last_name',
        'doc_type',
        'doc_number',
        'address',
        'email',
        'phone',
        'is_minor',
        'guardian_name',
        'guardian_doc_number',
        'good_type',
        'good_description',
        'amount',
        'currency',
        'order_reference',
        'detail',
        'request',
        'status',
        'provider',
        'due_at',
        'answered_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'is_minor' => 'boolean',
            'amount' => 'decimal:2',
            'provider' => 'array',
            'due_at' => 'date',
            'answered_at' => 'datetime',
        ];
    }

    public function isAnswered(): bool
    {
        return $this->status === self::STATUS_ANSWERED;
    }

    /**
     * Sin respuesta y con el plazo ya cumplido.
     */
    public function isOverdue(): bool
    {
        return ! $this->isAnswered() && $this->due_at->copy()->endOfDay()->isPast();
    }

    public function consumerName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function history(): HasMany
    {
        return $this->hasMany(ClaimStatusChange::class)->orderBy('created_at')->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ClaimMessage::class)->orderBy('created_at')->orderBy('id');
    }
}
