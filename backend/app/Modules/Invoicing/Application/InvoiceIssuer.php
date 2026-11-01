<?php

namespace App\Modules\Invoicing\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceSeries;
use App\Modules\Invoicing\Domain\Models\InvoicingSetting;
use App\Modules\Sales\Application\Exchange\ExchangeRateService;
use App\Modules\Sales\Domain\Billing;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Application\StaffNotifier;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Comprobantes electrónicos: uno por cada pago confirmado (también cada
 * cuota), boleta o factura según lo que pidió el comprador, con la serie y
 * el correlativo de la academia. Se envían al proveedor (Mifact), que los
 * pasa a SUNAT; el estado se consulta hasta que queda aceptado o rechazado.
 *
 * Un comprobante rechazado no se reutiliza: al reintentar sale otro número.
 */
class InvoiceIssuer
{
    /** Tasa del IGV */
    private const IGV_RATE = 0.18;

    /** SUNAT pide el documento del comprador en boletas desde este importe (en soles) */
    public const BOLETA_ID_THRESHOLD = 700;

    /** Intentos de envío automáticos antes de dejarlo para que el personal lo revise */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly MifactClient $mifact,
        private readonly AuditLogger $audit,
        private readonly StaffNotifier $staff,
        private readonly ExchangeRateService $rates,
    ) {}

    public function setting(): ?InvoicingSetting
    {
        return InvoicingSetting::query()->first();
    }

    /**
     * Emite el comprobante de un pago. Si la academia no emite comprobantes,
     * o el pago ya tiene uno vigente, no hace nada.
     */
    public function issueFor(Payment $payment): ?Invoice
    {
        $setting = $this->setting();

        if (! $setting?->enabled || (float) $payment->amount <= 0) {
            return null;
        }

        $existing = Invoice::query()->where('payment_id', $payment->id)->where('status', '!=', Invoice::STATUS_REJECTED)->first();

        return $existing ?? $this->send($this->create($setting, $payment), $setting);
    }

    /**
     * Envía (o reenvía) el comprobante y guarda lo que respondió el proveedor.
     */
    public function send(Invoice $invoice, ?InvoicingSetting $setting = null): Invoice
    {
        $setting ??= $this->setting();

        if ($setting === null) {
            return $invoice;
        }

        $invoice->forceFill(['attempts' => $invoice->attempts + 1, 'checked_at' => now()]);

        // Lo que SUNAT rechazaría seguro no se envía: el personal completa el dato y reintenta
        $missing = match (true) {
            $invoice->customer_doc_type === Invoice::DOC_NONE && $invoice->customer_doc_number === '' => 'Falta el documento del comprador: las boletas desde S/ '.self::BOLETA_ID_THRESHOLD.' (y las que no son en soles) lo piden. Complétalo en los datos del comprobante y reintenta.',
            $invoice->currency !== 'PEN' && ! $invoice->exchange_rate => 'Falta el tipo de cambio del día para emitir en dólares. Regístralo en Configuración → Tipo de cambio y reintenta.',
            default => null,
        };
        if ($missing !== null) {
            $invoice->attempts = self::MAX_ATTEMPTS;

            return $this->record($invoice, Invoice::STATUS_ERROR, null, $missing);
        }

        try {
            $response = $this->mifact->send($setting, $this->document($invoice, $setting));
        } catch (Throwable $error) {
            Log::warning("No se pudo enviar el comprobante {$invoice->code()}: ".$error->getMessage());

            return $this->record($invoice, Invoice::STATUS_ERROR, null, 'No se pudo conectar con Mifact. Se vuelve a intentar en unos minutos.');
        }

        if (str_contains((string) ($response['errors'] ?? ''), 'ya existe')) {
            // Un envío nuestro que llegó aunque no se recibió la respuesta: se toma su estado
            try {
                $found = $this->mifact->status($setting, $invoice->document_type, $invoice->series, $invoice->number, $invoice->issue_date->toDateString());
            } catch (Throwable) {
                $found = [];
            }
            if (($found['estado_documento'] ?? '') !== '') {
                return $this->apply($invoice, $found, $setting);
            }

            // Lo usó otro comprobante (emitido fuera del sistema, desde el portal de Mifact): pasa al siguiente número
            if ($invoice->attempts < self::MAX_ATTEMPTS) {
                return $this->send($this->renumber($invoice), $setting);
            }
        }

        return $this->apply($invoice, $response, $setting);
    }

    /**
     * Consulta el estado en el proveedor (para los que quedaron en proceso).
     */
    public function refresh(Invoice $invoice, ?InvoicingSetting $setting = null): Invoice
    {
        $setting ??= $this->setting();

        if ($setting === null) {
            return $invoice;
        }

        $invoice->checked_at = now();

        try {
            $response = $this->mifact->status($setting, $invoice->document_type, $invoice->series, $invoice->number, $invoice->issue_date->toDateString());
        } catch (Throwable $error) {
            Log::warning("No se pudo consultar el comprobante {$invoice->code()}: ".$error->getMessage());
            $invoice->save();

            return $invoice;
        }

        return $this->apply($invoice, $response, $setting);
    }

    /**
     * El personal lo vuelve a intentar: con los datos de facturación de la
     * orden como estén ahora. Uno rechazado se reemplaza por otro número.
     */
    public function retry(Invoice $invoice): Invoice
    {
        if ($invoice->isValid() || $invoice->status === Invoice::STATUS_PROCESSING) {
            throw new ApiException(ErrorCode::Conflict, 'Este comprobante ya está en SUNAT: no se vuelve a emitir.', 409);
        }

        $setting = $this->setting();

        if (! $setting?->enabled) {
            throw new ApiException(ErrorCode::Conflict, 'La academia no tiene activos los comprobantes electrónicos.', 409);
        }

        if ($invoice->status === Invoice::STATUS_REJECTED) {
            return $this->send($this->create($setting, $invoice->payment), $setting);
        }

        $invoice->fill($this->customer($invoice->order, (float) $invoice->total_amount, $invoice->currency))->forceFill(['attempts' => 0]);

        return $this->send($invoice, $setting);
    }

    /**
     * El PDF del proveedor (la representación impresa con su QR).
     */
    public function pdf(Invoice $invoice): ?string
    {
        $setting = $this->setting();

        if ($setting === null || ! $invoice->isValid()) {
            return null;
        }

        try {
            return $this->mifact->pdf($setting, $invoice->document_type, $invoice->series, $invoice->number, $invoice->issue_date->toDateString());
        } catch (Throwable $error) {
            Log::warning("No se pudo bajar el PDF del comprobante {$invoice->code()}: ".$error->getMessage());

            return null;
        }
    }

    /**
     * Crea el comprobante con el siguiente número de su serie.
     */
    private function create(InvoicingSetting $setting, Payment $payment): Invoice
    {
        return DB::transaction(function () use ($setting, $payment) {
            $order = $payment->order()->with(['customer', 'company', 'currency', 'items'])->firstOrFail();
            $payment->loadMissing('installment');
            // El pago va en la moneda de su orden
            $currency = $order->currency->code;
            $total = (float) $payment->amount;
            $customer = $this->customer($order, $total, $currency);
            $type = $customer['customer_doc_type'] === Invoice::DOC_RUC ? Invoice::TYPE_FACTURA : Invoice::TYPE_BOLETA;
            $series = $type === Invoice::TYPE_FACTURA ? $setting->factura_series : $setting->boleta_series;

            $counter = InvoiceSeries::query()->lockForUpdate()->firstOrCreate(['document_type' => $type, 'series' => $series], ['last_number' => 0]);
            $counter->increment('last_number');

            $exonerated = $setting->igv_mode === InvoicingSetting::IGV_EXONERATED;
            $taxable = $exonerated ? $total : round($total / (1 + self::IGV_RATE), 2);

            $invoice = Invoice::query()->create($customer + [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'document_type' => $type,
                'series' => $series,
                'number' => $counter->last_number,
                'issue_date' => now()->toDateString(),
                'currency' => $currency,
                'exchange_rate' => $currency === 'PEN' ? null : $this->rates->currentRate(),
                'taxable_amount' => $taxable,
                'igv_amount' => round($total - $taxable, 2),
                'total_amount' => $total,
                'igv_mode' => $setting->igv_mode,
                'description' => $this->description($order, $payment),
                'status' => Invoice::STATUS_PENDING,
            ]);

            $this->audit->record('invoices.created', $invoice, null, ['code' => $invoice->code(), 'order' => $order->number, 'total' => $invoice->total_amount]);

            return $invoice;
        });
    }

    /**
     * El siguiente número libre de su serie, para un comprobante que no llegó a emitirse.
     */
    private function renumber(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $counter = InvoiceSeries::query()->lockForUpdate()->where('document_type', $invoice->document_type)->where('series', $invoice->series)->firstOrFail();
            $counter->increment('last_number');
            $before = $invoice->code();
            $invoice->forceFill(['number' => $counter->last_number])->save();
            $this->audit->record('invoices.renumbered', $invoice, ['code' => $before], ['code' => $invoice->code(), 'reason' => 'El número ya estaba usado en Mifact.']);

            return $invoice;
        });
    }

    /**
     * El receptor según lo que pidió el comprador (o, sin datos, una boleta a su nombre).
     *
     * @return array{customer_doc_type: string, customer_doc_number: string, customer_name: string, customer_address: ?string}
     */
    private function customer(Order $order, float $total, string $currency): array
    {
        $billing = $order->billing
            ?? ($order->company ? Billing::forCompany($order->company) : null)
            ?? ($order->customer ? Billing::from(null, $order->customer) : null)
            ?? ['type' => Billing::BOLETA, 'doc_type' => null, 'doc_number' => null, 'name' => 'Cliente', 'address' => null];

        $docType = match ($billing['doc_type']) {
            Billing::DOC_RUC => Invoice::DOC_RUC,
            'DNI' => Invoice::DOC_DNI,
            'CE' => Invoice::DOC_CE,
            'PASAPORTE' => Invoice::DOC_PASSPORT,
            default => Invoice::DOC_NONE,
        };

        // Sin documento solo vale bajo el tope de SUNAT: si no, se rechazaría (el personal lo completa y reintenta)
        $overThreshold = $currency !== 'PEN' || $total >= self::BOLETA_ID_THRESHOLD;

        return [
            'customer_doc_type' => $docType,
            'customer_doc_number' => $docType === Invoice::DOC_NONE ? ($overThreshold ? '' : '-') : (string) $billing['doc_number'],
            'customer_name' => mb_substr($billing['name'] ?: 'Cliente', 0, 200),
            'customer_address' => $billing['address'] ?? null,
        ];
    }

    private function description(Order $order, Payment $payment): string
    {
        $product = $order->package_title
            ?? $order->items->pluck('title')->filter()->implode(', ')
            ?: 'Orden '.$order->number;

        $installment = $payment->installment;
        $label = $installment ? "Cuota {$installment->number} · {$product}" : $product;

        return mb_strimwidth($label, 0, 250, '…');
    }

    /**
     * El documento en el formato JSON de Mifact.
     *
     * @return array<string, mixed>
     */
    private function document(Invoice $invoice, InvoicingSetting $setting): array
    {
        $exonerated = $invoice->igv_mode === InvoicingSetting::IGV_EXONERATED;
        $total = self::amount($invoice->total_amount);
        $taxable = self::amount($invoice->taxable_amount);
        $igv = self::amount($invoice->igv_amount);

        return array_filter([
            'COD_TIP_NIF_EMIS' => '6',
            'NUM_NIF_EMIS' => $setting->ruc,
            'NOM_RZN_SOC_EMIS' => $setting->legal_name,
            'NOM_COMER_EMIS' => $setting->trade_name ?: $setting->legal_name,
            'COD_UBI_EMIS' => $setting->ubigeo,
            'TXT_DMCL_FISC_EMIS' => $setting->address,
            'COD_ANEXO_EMIS' => $setting->establishment_code,
            'COD_TIP_NIF_RECP' => $invoice->customer_doc_type,
            'NUM_NIF_RECP' => $invoice->customer_doc_number ?: '-',
            'NOM_RZN_SOC_RECP' => $invoice->customer_name,
            'TXT_DMCL_FISC_RECEP' => $invoice->customer_address ?: '-',
            'FEC_EMIS' => $invoice->issue_date->toDateString(),
            'FEC_VENCIMIENTO' => $invoice->issue_date->toDateString(),
            'COD_TIP_CPE' => $invoice->document_type,
            'NUM_SERIE_CPE' => $invoice->series,
            'NUM_CORRE_CPE' => str_pad((string) $invoice->number, 8, '0', STR_PAD_LEFT),
            'COD_MND' => $invoice->currency,
            'TIP_CAMBIO' => $invoice->exchange_rate ? number_format((float) $invoice->exchange_rate, 3, '.', '') : null,
            // El comprobante se lo manda la academia con su correo (no el del proveedor)
            'TXT_CORREO_ENVIO' => '',
            'COD_PRCD_CARGA' => '001',
            'MNT_TOT_GRAVADO' => $exonerated ? null : $taxable,
            'MNT_TOT_EXONERADO' => $exonerated ? $total : null,
            'MNT_TOT_TRIB_IGV' => $igv,
            'MNT_TOT' => $total,
            'COD_PTO_VENTA' => 'erp',
            'ENVIAR_A_SUNAT' => 'true',
            'RETORNA_XML_ENVIO' => 'false',
            'RETORNA_XML_CDR' => 'false',
            'RETORNA_PDF' => 'false',
            'COD_FORM_IMPR' => '001',
            'TXT_VERS_UBL' => '2.1',
            'TXT_VERS_ESTRUCT_UBL' => '2.0',
            'COD_TIP_OPE_SUNAT' => '0101',
            'items' => [[
                'COD_ITEM' => 'ORD-'.$invoice->order()->value('number'),
                // ZZ: unidad de servicio (catálogo 03 de SUNAT)
                'COD_UNID_ITEM' => 'ZZ',
                'CANT_UNID_ITEM' => '1',
                'VAL_UNIT_ITEM' => $taxable,
                'PRC_VTA_UNIT_ITEM' => $total,
                'VAL_VTA_ITEM' => $taxable,
                'MNT_BRUTO' => $taxable,
                'MNT_PV_ITEM' => $total,
                'COD_TIP_PRC_VTA' => '01',
                // 10: gravado con IGV; 20: exonerado
                'COD_TIP_AFECT_IGV_ITEM' => $exonerated ? '20' : '10',
                'COD_TRIB_IGV_ITEM' => $exonerated ? '9997' : '1000',
                'POR_IGV_ITEM' => $exonerated ? '0' : '18',
                'MNT_IGV_ITEM' => $igv,
                'TXT_DESC_ITEM' => $invoice->description,
            ]],
        ], fn ($value) => $value !== null);
    }

    /**
     * Guarda la respuesta del proveedor y avisa al personal si SUNAT lo rechazó o no se pudo emitir.
     *
     * @param  array<string, mixed>  $response
     */
    private function apply(Invoice $invoice, array $response, InvoicingSetting $setting): Invoice
    {
        $code = (string) ($response['estado_documento'] ?? '');
        $error = trim((string) ($response['errors'] ?? ''));

        [$status, $message] = match ($code) {
            '102' => [Invoice::STATUS_ACCEPTED, $response['sunat_description'] ?? null],
            '103' => [Invoice::STATUS_OBSERVED, trim(($response['sunat_description'] ?? '').' '.($response['sunat_note'] ?? ''))],
            '104' => [Invoice::STATUS_REJECTED, $response['sunat_description'] ?: MifactClient::readable($error)],
            '101', '108' => [Invoice::STATUS_PROCESSING, 'Enviado a SUNAT; esperando su respuesta.'],
            default => [Invoice::STATUS_ERROR, MifactClient::readable($error)],
        };

        if ($status === Invoice::STATUS_ACCEPTED || $status === Invoice::STATUS_OBSERVED) {
            $setting->forceFill(['last_ok_at' => now(), 'last_error' => null])->save();
        } elseif ($status === Invoice::STATUS_ERROR) {
            $setting->forceFill(['last_error' => mb_substr((string) $message, 0, 500)])->save();
        }

        $invoice->forceFill(['hash' => ($response['codigo_hash'] ?? null) ?: $invoice->hash]);

        return $this->record($invoice, $status, $code !== '' ? $code : null, $message);
    }

    private function record(Invoice $invoice, string $status, ?string $code, ?string $message): Invoice
    {
        $before = $invoice->getOriginal('status');
        $invoice->forceFill([
            'status' => $status,
            'provider_status' => $code,
            'provider_message' => $message === null ? null : mb_substr($message, 0, 500),
        ])->save();

        if ($before !== $status) {
            $this->audit->record('invoices.'.strtolower($status), $invoice, ['status' => $before], ['status' => $status, 'code' => $invoice->code(), 'message' => $invoice->provider_message]);
        }

        // Rechazado, o sin poder emitirse al llegar al último intento automático: alguien tiene que revisarlo
        $rejected = $status === Invoice::STATUS_REJECTED && $before !== Invoice::STATUS_REJECTED;
        $stuck = $status === Invoice::STATUS_ERROR && $invoice->attempts === self::MAX_ATTEMPTS;
        if ($rejected || $stuck) {
            $order = $invoice->order()->first();
            $this->staff->notify('payments.confirm', 'personal_comprobante_con_problema', [
                'comprobante' => $invoice->code(),
                'orden' => $order ? '#'.str_pad((string) $order->number, 5, '0', STR_PAD_LEFT) : '',
                'motivo' => (string) $invoice->provider_message,
            ], '/billing');
        }

        return $invoice;
    }

    private static function amount(string|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
