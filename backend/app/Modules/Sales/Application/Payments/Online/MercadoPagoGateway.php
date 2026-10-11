<?php

namespace App\Modules\Sales\Application\Payments\Online;

use App\Modules\Sales\Domain\Models\Payment;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mercado Pago, con su checkout alojado (Checkout Pro): quien paga lo hace
 * en la página de Mercado Pago con tarjeta, Yape o su saldo, y vuelve.
 * Cobra en soles con la cuenta que se le entregue.
 */
class MercadoPagoGateway implements OnlineGateway
{
    public const CODE = 'MERCADOPAGO';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Mercado Pago';
    }

    public function chargeCurrency(string $currency): ?string
    {
        // Una cuenta peruana cobra solo en soles
        return $currency === 'PEN' ? 'PEN' : null;
    }

    public function tracksByCheckout(): bool
    {
        return false;
    }

    public function credentialRules(): array
    {
        return [
            'access_token' => ['required', 'string', 'max:300'],
            // Clave con la que Mercado Pago firma sus avisos (opcional)
            'webhook_secret' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function describeAccount(array $credentials): array
    {
        $response = $this->send(fn () => $this->client((string) $credentials['access_token'])->get('/users/me'));

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ApiException(ErrorCode::ValidationError, 'Los datos no son válidos.', 422, [
                'access_token' => ['Mercado Pago no reconoce esa clave. Copia el «Access Token» de tu aplicación.'],
            ]);
        }

        $this->assertOk($response);

        return [
            'name' => $response->json('nickname') ?: $response->json('email'),
            'country' => $response->json('site_id'),
            // Las claves de prueba no mueven dinero; las de un usuario de prueba empiezan con APP_USR- como las reales
            'test' => $this->usesSandbox((string) $credentials['access_token'])
                || str_starts_with((string) $response->json('nickname'), 'TESTUSER')
                || in_array('test_user', (array) $response->json('tags'), true),
        ];
    }

    public function startCheckout(GatewayCredentials $account, Charge $charge, array $urls): array
    {
        $secure = str_starts_with($urls['return'], 'https://');

        $response = $this->send(fn () => $this->client($account->get('access_token'))->post('/checkout/preferences', array_filter([
            'items' => [[
                'id' => $charge->reference,
                'title' => mb_substr($charge->title, 0, 250),
                'quantity' => 1,
                'unit_price' => (float) $charge->amount,
                'currency_id' => $charge->currency,
            ]],
            'payer' => [
                'email' => $charge->payerEmail,
                'name' => $charge->payerFirstName,
                'surname' => $charge->payerLastName,
            ],
            // Con esto Mercado Pago nos dice después qué se pagó
            'external_reference' => $charge->reference,
            'back_urls' => ['success' => $urls['return'], 'pending' => $urls['return'], 'failure' => $urls['return']],
            // Volver solo al aprobarse y recibir avisos exigen una dirección pública con HTTPS
            'auto_return' => $secure ? 'approved' : null,
            'notification_url' => $urls['webhook'],
            // Pasado el plazo, Mercado Pago ya no lo cobra
            'expires' => $charge->expiresAt !== null,
            'expiration_date_to' => $charge->expiresAt?->format('Y-m-d\TH:i:s.vP'),
        ], fn ($value) => $value !== null)));

        $this->assertOk($response);

        return [
            'external_id' => (string) $response->json('id'),
            // Un usuario de prueba paga en la página normal; la de sandbox es solo para las claves TEST-
            'checkout_url' => (string) ($this->usesSandbox((string) $account->get('access_token')) ? ($response->json('sandbox_init_point') ?? $response->json('init_point')) : $response->json('init_point')),
        ];
    }

    public function paymentsFor(GatewayCredentials $account, string $reference, ?string $checkoutId = null): array
    {
        $response = $this->send(fn () => $this->client($account->get('access_token'))->get('/v1/payments/search', [
            'external_reference' => $reference,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]));

        $this->assertOk($response);

        return array_map(fn (array $payment) => $this->payment($payment), $response->json('results') ?? []);
    }

    public function paymentFromWebhook(GatewayCredentials $account, Request $request): ?GatewayPayment
    {
        // El identificador llega en la dirección (data.id) y en el cuerpo; PHP lee «data.id» como «data_id»
        $id = (string) ($request->query('data_id') ?? $request->input('data.id') ?? '');
        $type = $request->query('type') ?? $request->input('type') ?? $request->query('topic');

        if ($type !== 'payment' || $id === '') {
            return null;
        }

        $this->assertSignature($account, $request, $id);

        $response = $this->send(fn () => $this->client($account->get('access_token'))->get("/v1/payments/{$id}"));

        if ($response->status() === 404) {
            return null;
        }

        $this->assertOk($response);

        return $this->payment($response->json());
    }

    public function refund(GatewayCredentials $account, string $paymentId): string
    {
        // Sin importe en el cuerpo, Mercado Pago devuelve el pago completo
        $response = $this->send(fn () => $this->client($account->get('access_token'))
            ->withHeaders(['X-Idempotency-Key' => "refund-{$paymentId}"])
            ->withBody('{}', 'application/json')
            ->post("/v1/payments/{$paymentId}/refunds"));

        $this->assertRefunded($response);

        return (string) $response->json('id');
    }

    /**
     * Si se guardó la clave de firma, un aviso sin firma válida se rechaza.
     * Sin ella se acepta: de todos modos el pago se consulta en Mercado Pago
     * con la cuenta.
     */
    private function assertSignature(GatewayCredentials $account, Request $request, string $id): void
    {
        $secret = $account->get('webhook_secret');

        if ($secret === null || $secret === '') {
            return;
        }

        $parts = [];
        foreach (explode(',', (string) $request->header('x-signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $manifest = 'id:'.strtolower($id).';request-id:'.$request->header('x-request-id').';ts:'.($parts['ts'] ?? '').';';

        if (! hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1'] ?? '')) {
            throw new ApiException(ErrorCode::Forbidden, 'La firma del aviso no es válida.', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function payment(array $payment): GatewayPayment
    {
        return new GatewayPayment(
            id: (string) $payment['id'],
            status: match ($payment['status'] ?? null) {
                'approved' => GatewayPayment::APPROVED,
                'refunded', 'charged_back' => GatewayPayment::REFUNDED,
                'rejected', 'cancelled' => GatewayPayment::REJECTED,
                default => GatewayPayment::PENDING,
            },
            amount: number_format((float) ($payment['transaction_amount'] ?? 0), 2, '.', ''),
            currency: (string) ($payment['currency_id'] ?? ''),
            orderReference: $payment['external_reference'] ?? null,
            method: match (true) {
                ($payment['payment_method_id'] ?? null) === 'yape' => Payment::METHOD_YAPE,
                in_array($payment['payment_type_id'] ?? null, ['credit_card', 'debit_card', 'prepaid_card'], true) => Payment::METHOD_CARD,
                ($payment['payment_type_id'] ?? null) === 'account_money' => Payment::METHOD_WALLET,
                in_array($payment['payment_type_id'] ?? null, ['bank_transfer', 'atm', 'ticket'], true) => Payment::METHOD_TRANSFER,
                default => Payment::METHOD_OTHER,
            },
            paidAt: isset($payment['date_approved']) ? Carbon::parse($payment['date_approved'])->utc() : null,
        );
    }

    private function usesSandbox(string $accessToken): bool
    {
        return str_starts_with($accessToken, 'TEST-');
    }

    private function client(?string $accessToken): PendingRequest
    {
        return Http::baseUrl(config('payments.mercadopago.base_url'))
            ->withToken((string) $accessToken)
            ->acceptJson()
            ->asJson()
            ->withOptions(array_filter(['verify' => config('payments.ca_bundle')]))
            ->timeout(config('payments.timeout'));
    }

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            // La causa (sin conexión, certificados, tiempo agotado) queda en el registro del servidor
            Log::warning('Mercado Pago no respondió: '.$e->getMessage());

            throw new ApiException(ErrorCode::GatewayError, 'Mercado Pago no respondió. Inténtalo de nuevo en unos minutos.', 502);
        }
    }

    /**
     * Una devolución que la pasarela no acepta (pago ya devuelto, plazo
     * vencido, saldo insuficiente) se explica a quien la pidió.
     */
    private function assertRefunded(Response $response): void
    {
        if ($response->clientError() && $response->status() !== 401) {
            throw new ApiException(
                ErrorCode::GatewayError,
                'Mercado Pago no aceptó la devolución'.(($reason = $response->json('message')) ? " ({$reason})" : '').'. Revisa el pago en tu cuenta de Mercado Pago.',
                502,
                ['gateway_status' => $response->status()]
            );
        }

        $this->assertOk($response);
    }

    private function assertOk(Response $response): void
    {
        if ($response->failed()) {
            // Lo que dijo Mercado Pago queda en el registro del servidor: sin eso no se sabe por qué rechazó
            Log::warning('Mercado Pago rechazó una operación.', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            // La clave es válida, pero la aplicación de Mercado Pago aún no tiene activadas sus credenciales de producción
            $notActivated = $response->status() === 401 && str_contains((string) $response->json('message'), 'live credentials');

            throw new ApiException(
                ErrorCode::GatewayError,
                match (true) {
                    $notActivated => 'Mercado Pago no permite esta operación porque las credenciales de producción de tu aplicación no están activadas. Actívalas en Mercado Pago Developers (Tus integraciones → tu aplicación → Credenciales de producción) y vuelve a intentarlo. Mientras tanto puedes hacer la devolución desde tu cuenta de Mercado Pago.',
                    $response->status() === 401 => 'Mercado Pago rechazó la clave guardada. Hay que volver a conectar la cuenta.',
                    default => 'Mercado Pago no pudo atender la operación. Inténtalo de nuevo en unos minutos.',
                },
                502,
                ['gateway_status' => $response->status()]
            );
        }
    }
}
