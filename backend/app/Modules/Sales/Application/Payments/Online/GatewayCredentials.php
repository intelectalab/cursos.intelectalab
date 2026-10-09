<?php

namespace App\Modules\Sales\Application\Payments\Online;

/**
 * Cuenta con la que se cobra en una pasarela: sus claves, ya descifradas,
 * y si es una cuenta de prueba. Vive solo durante la petición.
 */
final readonly class GatewayCredentials
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        private array $values,
        public bool $test = false,
    ) {}

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }
}
