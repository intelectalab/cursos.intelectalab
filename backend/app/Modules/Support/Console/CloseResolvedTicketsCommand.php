<?php

namespace App\Modules\Support\Console;

use App\Modules\Support\Application\TicketService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

class CloseResolvedTicketsCommand extends Command
{
    protected $signature = 'tickets:close-resolved';

    protected $description = 'Cierra los tickets resueltos que llevan más de una semana sin respuesta';

    public function handle(TicketService $tickets, TenantContext $context): int
    {
        $closed = 0;

        // Organización por organización, para que la auditoría quede en la suya
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $closed += $context->runForTenant($tenantId, fn () => $tickets->closeStale());
        }

        $this->info("Tickets cerrados: {$closed}");

        return self::SUCCESS;
    }
}
