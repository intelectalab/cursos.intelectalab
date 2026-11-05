<?php

namespace App\Modules\Invoicing\Jobs;

use App\Modules\Invoicing\Application\InvoiceIssuer;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Http\InvoicePresenter;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Le envía al comprador su comprobante aceptado, con el PDF adjunto, desde el
 * correo de la academia. Si Mifact todavía no da el PDF, se vuelve a intentar
 * en unos minutos; al último intento sale sin adjunto (lo descarga en «Mis compras»).
 */
class SendInvoiceMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Cómo se nombra cada tipo dentro del correo */
    private const TYPES = ['BOLETA' => 'boleta', 'FACTURA' => 'factura', 'NOTA_CREDITO' => 'nota de crédito'];

    /**
     * @param  bool  $resend  el personal lo pidió otra vez («Reenviar»)
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $invoiceId,
        public readonly bool $resend = false,
    ) {}

    public function handle(TenantContext $context, InvoiceIssuer $issuer, TenantNotifier $notifier): void
    {
        $context->runForTenant($this->tenantId, function () use ($issuer, $notifier) {
            $invoice = Invoice::query()->with('order.customer', 'order.currency')->find($this->invoiceId);

            // Ya no vale, o ya salió (dos avisos seguidos de SUNAT): no se manda dos veces
            if ($invoice === null || ! $invoice->isValid() || ($invoice->emailed_at !== null && ! $this->resend) || $invoice->order?->customer === null) {
                return;
            }

            $pdf = $issuer->pdf($invoice);
            if ($pdf === null && $this->attempts() < $this->tries) {
                $this->release(120);

                return;
            }

            $order = $invoice->order;
            $notifier->notify($order->customer, 'comprobante_emitido', [
                'tipo' => self::TYPES[InvoicePresenter::TYPES[$invoice->document_type]],
                'comprobante' => $invoice->code(),
                'orden' => 'N.º '.str_pad((string) $order->number, 5, '0', STR_PAD_LEFT),
                'importe' => TenantNotifier::money($invoice->currency, $invoice->total_amount),
            ], "/my-orders/{$order->id}", $pdf === null ? [] : [
                ['name' => "{$invoice->code()}.pdf", 'mime' => 'application/pdf', 'data' => base64_encode($pdf)],
            ]);

            $invoice->forceFill(['emailed_at' => now()])->save();
        });
    }
}
