<?php

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Payment;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un comprobante electrónico (boleta o factura) emitido por un pago.
 */
class Invoice extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_FACTURA = '01';

    public const TYPE_BOLETA = '03';

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

    /** Estados que todavía pueden cambiar solos */
    public const OPEN = [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_ERROR];

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
        'document_type',
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
        'status',
        'provider_status',
        'provider_message',
        'hash',
        'attempts',
        'checked_at',
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

    /** Como se lee en el comprobante: B001-00000012 */
    public function code(): string
    {
        return $this->series.'-'.str_pad((string) $this->number, 8, '0', STR_PAD_LEFT);
    }

    public function isValid(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_OBSERVED], true);
    }
}
