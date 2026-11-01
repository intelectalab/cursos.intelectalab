<?php

namespace App\Modules\Invoicing\Listeners;

use App\Modules\Invoicing\Jobs\IssueInvoice;
use App\Modules\Sales\Domain\Events\PaymentConfirmed;

/**
 * Cada pago confirmado lleva su comprobante, recién cuando el pago quedó guardado.
 */
class IssueInvoiceOnPayment
{
    public function handle(PaymentConfirmed $event): void
    {
        IssueInvoice::dispatch($event->payment->tenant_id, $event->payment->id)->afterCommit();
    }
}
