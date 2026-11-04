<?php

namespace App\Modules\Invoicing\Jobs;

use App\Modules\Invoicing\Application\InvoiceIssuer;
use App\Modules\Sales\Domain\Models\Payment;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emite en segundo plano la nota de crédito de un pago devuelto: el reembolso no espera a SUNAT.
 */
class IssueCreditNote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $paymentId,
    ) {}

    public function handle(TenantContext $context, InvoiceIssuer $issuer): void
    {
        // Una nota que falla nunca deshace la devolución: queda en el registro y la reintenta invoices:check
        try {
            $context->runForTenant($this->tenantId, function () use ($issuer) {
                $payment = Payment::query()->find($this->paymentId);

                if ($payment !== null) {
                    $issuer->creditNoteFor($payment, (string) $payment->refund_reason);
                }
            });
        } catch (Throwable $error) {
            Log::error("No se pudo emitir la nota de crédito del pago {$this->paymentId}: ".$error->getMessage());
        }
    }
}
