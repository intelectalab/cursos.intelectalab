<?php

namespace App\Modules\B2b\Domain\Models;

use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Crm\Domain\Models\Contact;
use App\Modules\Crm\Domain\Models\Opportunity;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Currency;
use App\Modules\Sales\Domain\Models\Order;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SENT = 'SENT';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_REJECTED = 'REJECTED';

    /** Situación calculada: enviada y fuera de plazo */
    public const STATE_EXPIRED = 'EXPIRED';

    public const STATES = [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATE_EXPIRED];

    public const VIA_PORTAL = 'PORTAL';

    public const VIA_STAFF = 'STAFF';

    /** Días de vigencia que se proponen al crear una cotización */
    public const DEFAULT_VALIDITY_DAYS = 15;

    protected $fillable = [
        'tenant_id',
        'number',
        'company_id',
        'contact_id',
        'opportunity_id',
        'currency_id',
        'status',
        'version',
        'valid_until',
        'notes',
        'total_amount',
        'sent_at',
        'accepted_at',
        'accepted_by',
        'accepted_via',
        'acceptance_note',
        'rejected_at',
        'reject_reason',
        'order_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'version' => 'integer',
            'valid_until' => 'date',
            'total_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * Situación de hoy: una enviada cuyo plazo pasó cuenta como vencida.
     */
    public function state(): string
    {
        return $this->status === self::STATUS_SENT && $this->valid_until->copy()->endOfDay()->isPast()
            ? self::STATE_EXPIRED
            : $this->status;
    }

    /**
     * Filtra por la situación de hoy (ver state()).
     *
     * @param  Builder<Quotation>  $query
     */
    public function scopeInState(Builder $query, string $state): void
    {
        $today = now()->toDateString();

        match ($state) {
            self::STATUS_SENT => $query->where('status', self::STATUS_SENT)->whereDate('valid_until', '>=', $today),
            self::STATE_EXPIRED => $query->where('status', self::STATUS_SENT)->whereDate('valid_until', '<', $today),
            default => $query->where('status', $state),
        };
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuotationVersion::class)->orderByDesc('version');
    }
}
