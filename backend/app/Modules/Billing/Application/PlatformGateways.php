<?php

namespace App\Modules\Billing\Application;

use App\Modules\Sales\Application\Payments\Online\ChargeQuote;
use App\Modules\Sales\Application\Payments\Online\GatewayCredentials;
use App\Modules\Sales\Application\Payments\Online\OnlineGateway;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Cuentas de la plataforma en las pasarelas de pago: con ellas cobra los
 * planes a las organizaciones. Son las mismas pasarelas con las que cada
 * organización cobra a sus alumnos, pero con la cuenta de la plataforma.
 * Las claves se guardan cifradas en los ajustes de la plataforma.
 */
class PlatformGateways
{
    private const SETTING_PREFIX = 'payment_gateway_';

    /** Moneda en la que hoy se ofrecen los planes: decide qué se avisa en la consola */
    private const PLAN_CURRENCY = 'PEN';

    /**
     * @param  iterable<OnlineGateway>  $gateways
     */
    public function __construct(private readonly iterable $gateways) {}

    public function gateway(string $code): OnlineGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->code() === $code) {
                return $gateway;
            }
        }

        throw new ApiException(ErrorCode::NotFound, 'Esa pasarela de pago no existe.', 404);
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(fn (OnlineGateway $gateway) => $gateway->code(), [...$this->gateways]);
    }

    /**
     * Las pasarelas y cómo las tiene la plataforma, sin sus claves.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        return array_map(function (OnlineGateway $gateway) {
            $account = $this->stored($gateway->code());
            $credentials = $this->credentials($gateway->code());

            return [
                'code' => $gateway->code(),
                'label' => $gateway->label(),
                'connected' => $account !== null,
                'enabled' => (bool) ($account['enabled'] ?? false),
                'account' => $account['display'] ?? null,
                'currency' => self::PLAN_CURRENCY,
                'currency_supported' => true,
                // Cobra en otra moneda que la de los planes: hace falta el tipo de cambio para ofrecerla
                'needs_exchange_rate' => ChargeQuote::needsRate($gateway, self::PLAN_CURRENCY),
                'exchange_rate' => $this->exchangeRate($gateway->code()),
                'has_webhook_secret' => filled($credentials?->get('webhook_secret')) || filled($credentials?->get('webhook_id')),
            ];
        }, [...$this->gateways]);
    }

    /**
     * Conecta la cuenta de la plataforma, después de comprobar las claves con la pasarela.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function connect(string $code, array $credentials, bool $enabled = true): void
    {
        $display = $this->gateway($code)->describeAccount($credentials);

        $this->store($code, [
            'credentials' => Crypt::encryptString((string) json_encode(array_filter($credentials, fn ($value) => is_string($value) && filled($value)))),
            'display' => $display,
            'enabled' => $enabled,
        ] + ($this->stored($code) ?? []));
    }

    public function setEnabled(string $code, bool $enabled): void
    {
        $this->store($code, ['enabled' => $enabled] + $this->connected($code));
    }

    /**
     * Tipo de cambio con el que la plataforma cobra en dólares los planes
     * que ofrece en soles: cuántos soles vale un dólar.
     */
    public function setExchangeRate(string $code, float $penPerUsd): void
    {
        $this->store($code, ['exchange_rate' => $penPerUsd] + $this->connected($code));
    }

    public function disconnect(string $code): void
    {
        DB::table('platform_settings')->where('key', self::SETTING_PREFIX.$code)->delete();
    }

    /**
     * Pasarelas con las que la plataforma puede cobrar ese importe ahora, y
     * cuánto cobraría cada una si no cobra en esa moneda.
     *
     * @return list<array{code: string, label: string, charge: array{currency: string, amount: string, rate: ?string}|null}>
     */
    public function availableFor(string $amount, string $currency): array
    {
        $available = [];

        foreach ($this->gateways as $gateway) {
            $quote = $this->quote($gateway->code(), $amount, $currency);

            if ($quote !== null) {
                $available[] = ['code' => $gateway->code(), 'label' => $gateway->label(), 'charge' => $quote->rate !== null ? $quote->toArray() : null];
            }
        }

        return $available;
    }

    /**
     * Cuánto cobraría esa pasarela; null si no está activa o no puede cobrarlo.
     */
    public function quote(string $code, string $amount, string $currency): ?ChargeQuote
    {
        if (! ($this->stored($code)['enabled'] ?? false)) {
            return null;
        }

        return ChargeQuote::for($this->gateway($code), $amount, $currency, $this->exchangeRate($code));
    }

    /**
     * La cuenta de la plataforma lista para cobrar; null si no está conectada.
     */
    public function credentials(string $code): ?GatewayCredentials
    {
        $account = $this->stored($code);

        if ($account === null) {
            return null;
        }

        return new GatewayCredentials(
            json_decode(Crypt::decryptString($account['credentials']), true) ?: [],
            (bool) ($account['display']['test'] ?? false)
        );
    }

    private function exchangeRate(string $code): ?float
    {
        $rate = $this->stored($code)['exchange_rate'] ?? null;

        return $rate === null ? null : (float) $rate;
    }

    /**
     * @return array<string, mixed>
     */
    private function connected(string $code): array
    {
        return $this->stored($code) ?? throw new ApiException(ErrorCode::GatewayNotAvailable, 'Primero hay que conectar la cuenta.', 409);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(string $code): ?array
    {
        $value = DB::table('platform_settings')->where('key', self::SETTING_PREFIX.$code)->value('value');

        return $value ? json_decode($value, true) : null;
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function store(string $code, array $account): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => self::SETTING_PREFIX.$code],
            ['value' => json_encode($account), 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
