<?php

namespace App\Modules\Sales\Domain\Events;

use App\Modules\Sales\Domain\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se devolvió el dinero de un pago (la orden completa o una cuota). Quien
 * emite los comprobantes lo escucha para la nota de crédito; Ventas no sabe de ellos.
 */
class PaymentRefunded
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
