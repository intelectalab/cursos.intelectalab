<?php

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un comprobante electrónico: la boleta o factura de un pago, o la nota de
 * crédito que corrige una de ellas (al devolver el dinero, por ejemplo).
 */
class Invoice extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_FACTURA = '01';

    public const TYPE_BOLETA = '03';

    public const TYPE_CREDIT_NOTE = '07';

    /** Comprobantes de venta (los que respaldan un pago) */
    public const SALE_TYPES = [self::TYPE_FACTURA, self::TYPE_BOLETA];

    /** Tipo de nota de crédito (catálogo 09 de SUNAT): anulación de la operación */
    public const CREDIT_CANCELLATION = '01';

    /** Tipo de nota de crédito: devolución total (al reembolsar) */
    public const CREDIT_REFUND = '06';

    /** Días que SUNAT da para anular (comunicación de baja) desde la emisión; pasado el plazo, nota de crédito */
    public const VOID_DAYS = 7;

    /** Creado, todavía sin enviar al proveedor */
    public const STATUS_PENDING = 'PENDING';

    /** Recibido por el proveedor, esperando a SUNAT */
    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    /** Aceptado con observaciones: vale, pero SUNAT anotó algo */
    public const STATUS_OBSERVED = 'OBSERVED';

    /** SUNAT no lo aceptó: hay que corregir y emitir otro */
    public const STATUS_REJECTED = 'REJECTED';

    /** No se pudo enviar (conexión o datos): se reintenta */
    public const STATUS_ERROR = 'ERROR';

    /** Se pidió anular y SUNAT todavía no responde (la baja se procesa al final del día) */
    public const STATUS_VOIDING = 'VOIDING';

    /** Anulado en SUNAT: ya no vale */
    public const STATUS_VOIDED = 'VOIDED';

    /** No llegó a emitirse y ya no hace falta: el pago se devolvió antes */
    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_ACCEPTED, self::STATUS_OBSERVED,
        self::STATUS_REJECTED, self::STATUS_ERROR, self::STATUS_VOIDING, self::STATUS_VOIDED, self::STATUS_CANCELLED,
    ];

    /** Estados que todavía pueden cambiar solos */
    public const OPEN = [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_ERROR, self::STATUS_VOIDING];

    /** Estados que se consultan a SUNAT (los demás abiertos se envían) */
    public const AWAITING_SUNAT = [self::STATUS_PROCESSING, self::STATUS_VOIDING];

    /** Ya no cuentan: se puede emitir otro en su lugar */
    public const DISCARDED = [self::STATUS_REJECTED, self::STATUS_VOIDED, self::STATUS_CANCELLED];

    /** Tipo de documento del receptor en SUNAT */
    public const DOC_NONE = '0';

    public const DOC_DNI = '1';

    public const DOC_CE = '4';

    public const DOC_RUC = '6';

    public const DOC_PASSPORT = '7';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'payment_id',
        'reference_invoice_id',
        'document_type',
        'credit_type',
        'series',
        'number',
        'issue_date',
        'currency',
        'exchange_rate',
        'taxable_amount',
        'igv_amount',
        'total_amount',
        'igv_mode',
        'customer_doc_type',
        'customer_doc_number',
        'customer_name',
        'customer_address',
        'description',
        'reason',
        'status',
        'provider_status',
        'provider_message',
        'hash',
        'attempts',
        'checked_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'issue_date' => 'date',
            'exchange_rate' => 'decimal:3',
            'taxable_amount' => 'decimal:2',
            'igv_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'attempts' => 'integer',
            'checked_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** En una nota de crédito: el comprobante que corrige */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_invoice_id');
    }

    /** Las notas de crédito que corrigen este comprobante */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'reference_invoice_id');
    }

    /**
     * Solo boletas y facturas (sin las notas de crédito).
     *
     * @param  Builder<self>  $query
     */
    public function scopeSales(Builder $query): void
    {
        $query->whereIn('document_type', self::SALE_TYPES);
    }

    /** Como se lee en el comprobante: B001-00000012 */
    public function code(): string
    {
        return $this->series.'-'.str_pad((string) $this->number, 8, '0', STR_PAD_LEFT);
    }

    public function isValid(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_OBSERVED], true);
    }

    public function isCreditNote(): bool
    {
        return $this->document_type === self::TYPE_CREDIT_NOTE;
    }

    /**
     * El día de hoy en la hora de la academia (la de Lima por defecto): con
     * esa fecha se emite. Con la del servidor (UTC), desde las 7 p. m. el
     * comprobante saldría con la fecha de mañana y SUNAT no lo aceptaría.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(app(TenantSettingsService::class)->timezone())->startOfDay();
    }

    /** Último día en que SUNAT acepta anularlo */
    public function voidDeadline(): CarbonInterface
    {
        return $this->issue_date->copy()->addDays(self::VOID_DAYS);
    }

    public function isPastVoidDeadline(): bool
    {
        return self::today()->toDateString() > $this->voidDeadline()->toDateString();
    }

    /**
     * Si ya tiene una nota de crédito que vale o va en camino.
     */
    public function isCredited(): bool
    {
        return $this->creditNotes()->whereNotIn('status', self::DISCARDED)->exists();
    }
}
