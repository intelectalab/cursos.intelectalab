<?php

namespace App\Modules\Sales\Application\Payments\Online;

/**
 * Cuánto y en qué moneda cobrará una pasarela un importe. Casi siempre es
 * el mismo importe; cuando la pasarela no cobra en esa moneda (PayPal no
 * cobra en soles) se convierte con el tipo de cambio que fijó quien cobra.
 */
final readonly class ChargeQuote
{
    /**
     * @param  string|null  $rate  soles por dólar usados; null si no hubo conversión
     */
    public function __construct(
        public string $currency,
        public string $amount,
        public ?string $rate = null,
    ) {}

    /**
     * Null si la pasarela no puede cobrar ese importe: no maneja la moneda, o
     * hace falta un tipo de cambio que aún no se definió.
     *
     * @param  float|null  $penPerUsd  tipo de cambio de quien cobra: soles por dólar
     */
    public static function for(OnlineGateway $gateway, string $amount, string $currency, ?float $penPerUsd): ?self
    {
        $target = $gateway->chargeCurrency($currency);

        if ($target === null) {
            return null;
        }

        if ($target === $currency) {
            return new self($currency, number_format((float) $amount, 2, '.', ''));
        }

        // La única conversión que se hace: de soles a dólares
        if ($currency !== 'PEN' || $target !== 'USD' || $penPerUsd === null || $penPerUsd <= 0) {
            return null;
        }

        return new self('USD', number_format(round((float) $amount / $penPerUsd, 2), 2, '.', ''), number_format($penPerUsd, 4, '.', ''));
    }

    /**
     * ¿Cobrar esa moneda con esta pasarela exige un tipo de cambio?
     */
    public static function needsRate(OnlineGateway $gateway, string $currency): bool
    {
        $target = $gateway->chargeCurrency($currency);

        return $target !== null && $target !== $currency;
    }

    /**
     * @return array{currency: string, amount: string, rate: ?string}
     */
    public function toArray(): array
    {
        return ['currency' => $this->currency, 'amount' => $this->amount, 'rate' => $this->rate];
    }

    /**
     * Texto para dejar constancia de la conversión junto al pago.
     */
    public function note(): ?string
    {
        return $this->rate === null ? null : "Cobrado {$this->currency} {$this->amount} (tipo de cambio {$this->rate} soles por dólar).";
    }
}
