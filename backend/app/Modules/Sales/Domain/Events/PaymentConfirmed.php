<?php

namespace App\Modules\Sales\Domain\Events;

use App\Modules\Sales\Domain\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se registró un pago confirmado (la orden completa o una cuota). Quien
 * emite los comprobantes lo escucha; Ventas no sabe de ellos.
 */
class PaymentConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
