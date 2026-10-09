<?php

namespace App\Modules\Sales\Application\Payments\Online;

use Carbon\CarbonInterface;

/**
 * Un pago tal como lo informa la pasarela, ya traducido a términos propios.
 */
final readonly class GatewayPayment
{
    public const APPROVED = 'APPROVED';

    public const PENDING = 'PENDING';

    public const REJECTED = 'REJECTED';

    public const REFUNDED = 'REFUNDED';

    /**
     * @param  string  $id  identificador del pago en la pasarela
     * @param  string|null  $orderReference  la orden que la pasarela dice que se pagó
     * @param  string  $method  uno de Payment::ONLINE_METHODS
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $amount,
        public string $currency,
        public ?string $orderReference,
        public string $method,
        public ?CarbonInterface $paidAt = null,
    ) {}

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
