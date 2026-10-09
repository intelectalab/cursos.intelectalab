<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Sales\Application\Payments\Online\GatewayCredentials;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta de la organización en una pasarela de pago. Las claves se guardan
 * cifradas y nunca salen por la API.
 */
class GatewayAccount extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'payment_gateway_accounts';

    protected $fillable = [
        'tenant_id',
        'gateway',
        'enabled',
        'credentials',
        'display',
        'settings',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'display' => 'array',
            'settings' => 'array',
        ];
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    /**
     * Soles por dólar con los que la organización cobra en dólares; null si no lo definió.
     */
    public function exchangeRate(): ?float
    {
        $rate = $this->settings['exchange_rate'] ?? null;

        return $rate === null ? null : (float) $rate;
    }

    /**
     * La cuenta tal como la necesita la pasarela para cobrar.
     */
    public function forGateway(): GatewayCredentials
    {
        return new GatewayCredentials($this->credentials ?? [], (bool) ($this->display['test'] ?? false));
    }
}
