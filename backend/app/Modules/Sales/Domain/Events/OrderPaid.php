<?php

namespace App\Modules\Sales\Domain\Events;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una orden quedó pagada. Se emite dentro de la misma transacción del pago:
 * lo que hagan quienes escuchan se confirma o se deshace junto con él.
 */
class OrderPaid
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly User $actor,
    ) {}
}
