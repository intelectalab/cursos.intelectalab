<?php

namespace App\Modules\Sales\Application\Payments\Online;

use Carbon\CarbonInterface;

/**
 * Lo que se le pide cobrar a una pasarela.
 */
final readonly class Charge
{
    /**
     * @param  string  $reference  con qué nos dirá la pasarela qué se pagó (el id de la orden)
     * @param  string  $title  lo que ve quien paga
     * @param  CarbonInterface|null  $expiresAt  hasta cuándo se puede pagar; null = sin plazo
     */
    public function __construct(
        public string $reference,
        public string $title,
        public string $amount,
        public string $currency,
        public string $payerEmail,
        public ?string $payerFirstName = null,
        public ?string $payerLastName = null,
        public ?CarbonInterface $expiresAt = null,
    ) {}
}
