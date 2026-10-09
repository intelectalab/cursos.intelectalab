<?php

namespace App\Modules\Billing\Application;

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

            return [
                'code' => $gateway->code(),
                'label' => $gateway->label(),
                'connected' => $account !== null,
                'enabled' => (bool) ($account['enabled'] ?? false),
                'account' => $account['display'] ?? null,
                'currency_supported' => true,
                'has_webhook_secret' => filled($this->credentials($gateway->code())?->get('webhook_secret')),
            ];
        }, [...$this->gateways]);
    }

    /**
     * Conecta la cuenta de la plataforma, después de comprobar las claves con la pasarela.
     *
     * @param  array<string, string|null>  $credentials
     */
    public function connect(string $code, array $credentials, bool $enabled = true): void
    {
        $display = $this->gateway($code)->describeAccount($credentials);

        $this->store($code, [
            'credentials' => Crypt::encryptString((string) json_encode(array_filter($credentials, fn ($value) => filled($value)))),
            'display' => $display,
            'enabled' => $enabled,
        ]);
    }

    public function setEnabled(string $code, bool $enabled): void
    {
        $account = $this->stored($code) ?? throw new ApiException(ErrorCode::GatewayNotAvailable, 'Primero hay que conectar la cuenta.', 409);

        $this->store($code, ['enabled' => $enabled] + $account);
    }

    public function disconnect(string $code): void
    {
        DB::table('platform_settings')->where('key', self::SETTING_PREFIX.$code)->delete();
    }

    /**
     * Pasarelas con las que la plataforma puede cobrar en esa moneda ahora.
     *
     * @return list<array{code: string, label: string}>
     */
    public function availableFor(string $currency): array
    {
        $available = [];

        foreach ($this->gateways as $gateway) {
            if (($this->stored($gateway->code())['enabled'] ?? false) && $gateway->supportsCurrency($currency)) {
                $available[] = ['code' => $gateway->code(), 'label' => $gateway->label()];
            }
        }

        return $available;
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

    /**
     * @return array{credentials: string, display: array<string, mixed>|null, enabled: bool}|null
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
