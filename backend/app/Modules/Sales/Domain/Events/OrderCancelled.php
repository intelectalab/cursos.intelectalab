<?php

namespace App\Modules\Sales\Domain\Events;

use App\Modules\Sales\Domain\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una orden pendiente fue cancelada. Se emite dentro de la misma transacción.
 */
class OrderCancelled
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
