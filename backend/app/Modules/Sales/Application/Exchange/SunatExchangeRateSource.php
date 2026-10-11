<?php

namespace App\Modules\Sales\Application\Exchange;

use App\Modules\Sales\Domain\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tipo de cambio oficial de SUNAT. SUNAT no ofrece un servicio propio para
 * consultarlo: se lee de un servicio de terceros que lo republica, cuya
 * dirección se configura. Se toma el valor de venta.
 */
class SunatExchangeRateSource implements ExchangeRateSource
{
    public function code(): string
    {
        return ExchangeRate::SOURCE_SUNAT;
    }

    public function label(): string
    {
        return 'SUNAT (tipo de cambio oficial, venta)';
    }

    public function today(): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withOptions(array_filter(['verify' => config('payments.ca_bundle')]))
                ->when(config('payments.exchange.sunat_token'), fn ($http, string $token) => $http->withToken($token))
                ->timeout((int) config('payments.timeout'))
                ->get((string) config('payments.exchange.sunat_url'));
        } catch (Throwable $e) {
            // La causa (sin conexión, certificados, tiempo agotado) queda en el registro del servidor
            Log::warning('El servicio del tipo de cambio de SUNAT no respondió: '.$e->getMessage());

            return null;
        }

        // Los servicios que lo republican nombran el valor de venta de una de estas formas
        $rate = (float) ($response->json('venta') ?? $response->json('sell_price') ?? $response->json('precioVenta') ?? 0);

        if ($response->failed() || $rate < 0.1 || $rate > 100) {
            Log::warning('El servicio del tipo de cambio de SUNAT respondió algo que no se pudo usar.', ['status' => $response->status()]);

            return null;
        }

        return ['rate' => $rate, 'date' => (string) ($response->json('fecha') ?? $response->json('date') ?? now()->toDateString())];
    }
}
