<?php

namespace App\Modules\B2b\Application;

use App\Modules\Sales\Domain\Events\OrderPaid;

/**
 * Venta corporativa pagada → licencias disponibles para la empresa (FP-04).
 */
class IssueLicensesOnOrderPaid
{
    public function __construct(private readonly LicenseService $licenses) {}

    public function handle(OrderPaid $event): void
    {
        $this->licenses->issueForOrder($event->order);
    }
}
