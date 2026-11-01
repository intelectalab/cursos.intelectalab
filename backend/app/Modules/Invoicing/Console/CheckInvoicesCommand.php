<?php

namespace App\Modules\Invoicing\Console;

use App\Modules\Invoicing\Application\InvoiceIssuer;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Cada 5 minutos: consulta a SUNAT los comprobantes que quedaron en proceso
 * y reintenta los que no se pudieron enviar (hasta 5 veces). Los de más de
 * una semana se dejan para el personal.
 */
class CheckInvoicesCommand extends Command
{
    protected $signature = 'invoices:check';

    protected $description = 'Consulta el estado de los comprobantes en proceso y reintenta los que no se pudieron enviar';

    public function handle(TenantContext $context, InvoiceIssuer $issuer): int
    {
        $pending = Invoice::query()
            ->whereIn('status', Invoice::OPEN)
            ->where('created_at', '>=', now()->subWeek())
            ->where('attempts', '<', InvoiceIssuer::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinutes(4)))
            ->orderBy('created_at')
            ->limit(200)
            ->get(['id', 'tenant_id']);

        foreach ($pending->groupBy('tenant_id') as $tenantId => $invoices) {
            $context->runForTenant($tenantId, function () use ($invoices, $issuer) {
                foreach (Invoice::query()->whereKey($invoices->pluck('id'))->get() as $invoice) {
                    $invoice->status === Invoice::STATUS_PROCESSING ? $issuer->refresh($invoice) : $issuer->send($invoice);
                }
            });
        }

        $this->info("Comprobantes revisados: {$pending->count()}.");

        return self::SUCCESS;
    }
}
