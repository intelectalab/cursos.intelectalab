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
 * Emite en segundo plano el comprobante de un pago: el pago no espera a SUNAT.
 */
class IssueInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $paymentId,
    ) {}

    public function handle(TenantContext $context, InvoiceIssuer $issuer): void
    {
        // Un comprobante que falla nunca deshace ni detiene el pago: queda en el registro
        try {
            $context->runForTenant($this->tenantId, function () use ($issuer) {
                $payment = Payment::query()->find($this->paymentId);

                if ($payment !== null) {
                    $issuer->issueFor($payment);
                }
            });
        } catch (Throwable $error) {
            Log::error("No se pudo emitir el comprobante del pago {$this->paymentId}: ".$error->getMessage());
        }
    }
}
