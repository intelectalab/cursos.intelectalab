<?php

namespace App\Modules\Invoicing\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Datos de emisión de la academia y su cuenta del proveedor de comprobantes.
 */
class InvoicingSetting extends Model
{
    use BelongsToTenant, HasUuids;

    public const IGV_INCLUDED = 'INCLUDED';

    public const IGV_EXONERATED = 'EXONERATED';

    public const IGV_MODES = [self::IGV_INCLUDED, self::IGV_EXONERATED];

    /** Ambiente de pruebas público de Mifact */
    public const DEMO_URL = 'https://demo.mifact.net.pe/api/invoiceService.svc';

    protected $fillable = [
        'tenant_id',
        'enabled',
        'service_url',
        'ruc',
        'legal_name',
        'trade_name',
        'address',
        'ubigeo',
        'establishment_code',
        'boleta_series',
        'factura_series',
        'igv_mode',
        'token',
        'last_error',
        'last_ok_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'token' => 'encrypted',
            'last_ok_at' => 'datetime',
        ];
    }

    public function isDemo(): bool
    {
        return rtrim($this->service_url, '/') === self::DEMO_URL;
    }
}
