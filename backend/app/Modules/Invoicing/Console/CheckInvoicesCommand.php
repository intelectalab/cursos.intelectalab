<?php

namespace App\Modules\Invoicing\Console;

use App\Modules\Invoicing\Application\InvoiceIssuer;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Cada 5 minutos: consulta a SUNAT los comprobantes que quedaron en proceso
 * o con la anulación pedida, reintenta los que no se pudieron enviar (hasta
 * 5 veces) y envía las notas de crédito cuyo comprobante ya fue aceptado.
 * Los de más de una semana se dejan para el personal.
 */
class CheckInvoicesCommand extends Command
{
    protected $signature = 'invoices:check';

    protected $description = 'Consulta el estado de los comprobantes en proceso y reintenta los que no se pudieron enviar';

    public function handle(TenantContext $context, InvoiceIssuer $issuer): int
    {
        $pending = Invoice::query()
            ->where(fn ($query) => $query
                ->where(fn ($open) => $open
                    ->whereIn('status', Invoice::OPEN)
                    ->where('created_at', '>=', now()->subWeek())
                    ->where('attempts', '<', InvoiceIssuer::MAX_ATTEMPTS))
                // Una anulación pedida se sigue hasta que SUNAT responda, aunque el comprobante sea más antiguo
                ->orWhere(fn ($voiding) => $voiding->where('status', Invoice::STATUS_VOIDING)->where('updated_at', '>=', now()->subWeek())))
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinutes(4)))
            ->orderBy('created_at')
            ->limit(200)
            ->get(['id', 'tenant_id']);

        foreach ($pending->groupBy('tenant_id') as $tenantId => $invoices) {
            $context->runForTenant($tenantId, function () use ($invoices, $issuer) {
                foreach (Invoice::query()->whereKey($invoices->pluck('id'))->get() as $invoice) {
                    in_array($invoice->status, Invoice::AWAITING_SUNAT, true) ? $issuer->refresh($invoice) : $issuer->send($invoice);
                }
            });
        }

        $this->info("Comprobantes revisados: {$pending->count()}.");

        return self::SUCCESS;
    }
}
