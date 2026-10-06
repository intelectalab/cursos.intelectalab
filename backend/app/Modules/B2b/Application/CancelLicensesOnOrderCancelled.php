<?php

namespace App\Modules\B2b\Application;

use App\Modules\Sales\Domain\Events\OrderCancelled;

/**
 * Venta corporativa cancelada → sus licencias sin asignar dejan de estar disponibles.
 */
class CancelLicensesOnOrderCancelled
{
    public function __construct(private readonly LicenseService $licenses) {}

    public function handle(OrderCancelled $event): void
    {
        $this->licenses->cancelForOrder($event->order);
    }
}
