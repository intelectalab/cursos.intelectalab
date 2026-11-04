<?php

namespace App\Modules\Invoicing\Listeners;

use App\Modules\Invoicing\Jobs\IssueCreditNote;
use App\Modules\Sales\Domain\Events\PaymentRefunded;

/**
 * Cada pago devuelto lleva su nota de crédito, recién cuando la devolución quedó guardada.
 */
class IssueCreditNoteOnRefund
{
    public function handle(PaymentRefunded $event): void
    {
        IssueCreditNote::dispatch($event->payment->tenant_id, $event->payment->id)->afterCommit();
    }
}
