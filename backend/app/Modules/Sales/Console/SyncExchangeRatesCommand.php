<?php

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Application\Exchange\ExchangeRateService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

class SyncExchangeRatesCommand extends Command
{
    protected $signature = 'exchange-rates:sync';

    protected $description = 'Actualiza el tipo de cambio de las organizaciones que lo tienen en automático';

    public function handle(ExchangeRateService $rates, TenantContext $context): int
    {
        $updated = 0;
        $failed = 0;
        // Cada fuente se consulta una sola vez, no una por organización
        $fetched = [];

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $context->runForTenant($tenantId, function () use ($rates, &$fetched, &$updated, &$failed) {
                $settings = $rates->settings();

                if ($settings['mode'] !== ExchangeRateService::MODE_AUTO) {
                    return;
                }

                if (! array_key_exists($settings['source'], $fetched)) {
                    $fetched[$settings['source']] = $rates->fetch($settings['source']);
                }

                // Si la fuente no respondió, la organización sigue con su último valor
                if ($fetched[$settings['source']] === null) {
                    $failed++;

                    return;
                }

                $rates->refresh(null, $fetched[$settings['source']]);
                $updated++;
            });
        }

        $this->info("Organizaciones actualizadas: {$updated}; sin respuesta de la fuente: {$failed}");

        return self::SUCCESS;
    }
}
