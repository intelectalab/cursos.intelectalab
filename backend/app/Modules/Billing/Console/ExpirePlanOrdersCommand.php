<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Application\PlanOrderService;
use Illuminate\Console\Command;

class ExpirePlanOrdersCommand extends Command
{
    protected $signature = 'plan-orders:expire';

    protected $description = 'Vence las órdenes de plan que pasaron su plazo sin que la organización informara un pago';

    public function handle(PlanOrderService $orders): int
    {
        $this->info('Órdenes de plan vencidas: '.$orders->expireOverdue());

        return self::SUCCESS;
    }
}
