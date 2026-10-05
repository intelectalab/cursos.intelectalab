<?php

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Application\OrderService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ExpireOrdersCommand extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Marca como vencidas las órdenes pendientes cuyo plazo de pago ya pasó';

    public function handle(OrderService $orders, TenantContext $context): int
    {
        $expired = 0;

        // Organización por organización, para que la auditoría quede en la suya
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $expired += $context->runForTenant($tenantId, fn () => $orders->expireOverdue());
        }

        $this->info("Órdenes vencidas: {$expired}");

        return self::SUCCESS;
    }
}
