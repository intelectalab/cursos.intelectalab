<?php

namespace App\Modules\Sales\Application\Exchange;

use App\Modules\Sales\Application\Payments\Online\GatewayErrors;
use App\Modules\Sales\Domain\Models\ExchangeRate;
use Illuminate\Support\Facades\Context;
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

    /** Por qué falló la última consulta, para decírselo a quien la pidió */
    public const FAILURE = 'exchange_rate_failure';

    public function today(): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withOptions(array_filter(['verify' => config('payments.ca_bundle')]))
                ->when(config('payments.exchange.sunat_token'), fn ($http, string $token) => $http->withToken($token))
                ->timeout((int) config('payments.timeout'))
                ->get((string) config('payments.exchange.sunat_url'));
        } catch (Throwable $e) {
            Context::addHidden(self::FAILURE, GatewayErrors::connection('El servicio del tipo de cambio de SUNAT', $e)->getMessage());

            return null;
        }

        // Los servicios que lo republican nombran el valor de venta de una de estas formas
        $rate = (float) ($response->json('venta') ?? $response->json('sell_price') ?? $response->json('precioVenta') ?? 0);

        if ($response->failed() || $rate < 0.1 || $rate > 100) {
            Log::warning('El servicio del tipo de cambio de SUNAT respondió algo que no se pudo usar.', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            $status = $response->status();
            Context::addHidden(self::FAILURE, match (true) {
                in_array($status, [401, 403], true) => "El servicio del tipo de cambio de SUNAT rechazó la consulta (error {$status}): la clave del servicio falta o ya no vale. Lo resuelve quien administra el servidor.",
                $status === 429 => 'El servicio del tipo de cambio de SUNAT ya recibió demasiadas consultas por hoy. Inténtalo más tarde.',
                $status >= 500 => "El servicio del tipo de cambio de SUNAT tiene un problema en este momento (error {$status} de su lado). Inténtalo de nuevo en unos minutos.",
                $response->failed() => "El servicio del tipo de cambio de SUNAT no aceptó la consulta (error {$status}).",
                default => 'El servicio del tipo de cambio de SUNAT respondió, pero sin un valor de venta que se pueda usar.',
            });

            return null;
        }

        return ['rate' => $rate, 'date' => (string) ($response->json('fecha') ?? $response->json('date') ?? now()->toDateString())];
    }
}
