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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PayPal, con su checkout alojado (Orders v2): quien paga aprueba el cobro
 * en la página de PayPal y vuelve; recién entonces se captura el dinero.
 * Cobra en dólares: lo que está en soles se convierte antes de llegar aquí.
 */
class PayPalGateway implements OnlineGateway
{
    public const CODE = 'PAYPAL';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function chargeCurrency(string $currency): ?string
    {
        // PayPal no cobra en soles: se cobra el equivalente en dólares
        return in_array($currency, ['USD', 'PEN'], true) ? 'USD' : null;
    }

    public function tracksByCheckout(): bool
    {
        return true;
    }

    public function credentialRules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:300'],
            'client_secret' => ['required', 'string', 'max:300'],
            // Identificador del webhook en PayPal: con él se comprueba que los avisos sean suyos (opcional)
            'webhook_id' => ['nullable', 'string', 'max:100'],
            // Claves del entorno de pruebas de PayPal (sandbox): no mueven dinero
            'sandbox' => ['nullable', 'boolean'],
        ];
    }

    public function describeAccount(array $credentials): array
    {
        $sandbox = (bool) ($credentials['sandbox'] ?? false);
        $clientId = (string) $credentials['client_id'];

        // Pedir un token es la forma de saber si PayPal reconoce las claves
        $response = $this->send(fn () => $this->tokenRequest($clientId, (string) $credentials['client_secret'], $sandbox));

        if ($response->status() === 401) {
            throw new ApiException(ErrorCode::ValidationError, 'Los datos no son válidos.', 422, [
                'client_id' => ['PayPal no reconoce esas claves. Revisa el Client ID y el Secret'.($sandbox ? ' de tu aplicación de pruebas.' : ', y si son de una aplicación de pruebas marca la casilla.')],
            ]);
        }

        $this->assertOk($response);

        return [
            // PayPal no entrega el nombre de la cuenta con estas claves: se muestra el final del Client ID
            'name' => 'Aplicación …'.substr($clientId, -6),
            'country' => null,
            'test' => $sandbox,
        ];
    }

    public function startCheckout(GatewayCredentials $account, Charge $charge, array $urls): array
    {
        $response = $this->send(fn () => $this->client($account)
            // La misma petición repetida no crea dos órdenes
            ->withHeaders(['PayPal-Request-Id' => (string) Str::uuid()])
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $charge->reference,
                    // Con esto PayPal nos dice después qué se pagó
                    'custom_id' => $charge->reference,
                    'description' => mb_substr($charge->title, 0, 127),
                    'amount' => ['currency_code' => $charge->currency, 'value' => $charge->amount],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'return_url' => $urls['return'],
                    'cancel_url' => $urls['return'],
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ]]],
            ]));

        $this->assertOk($response);

        $approve = collect($response->json('links') ?? [])->first(fn (array $link) => in_array($link['rel'] ?? null, ['payer-action', 'approve'], true));

        if ($approve === null) {
            throw new ApiException(ErrorCode::GatewayError, 'PayPal no devolvió a dónde enviar el pago. Inténtalo de nuevo.', 502);
        }

        return ['external_id' => (string) $response->json('id'), 'checkout_url' => (string) $approve['href']];
    }

    public function paymentsFor(GatewayCredentials $account, string $reference, ?string $checkoutId = null): array
    {
        if ($checkoutId === null) {
            return [];
        }

        $response = $this->send(fn () => $this->client($account)->get("/v2/checkout/orders/{$checkoutId}"));

        if ($response->status() === 404) {
            return [];
        }

        $this->assertOk($response);

        return $this->paymentsOf($account, $response->json());
    }

    public function paymentFromWebhook(GatewayCredentials $account, Request $request): ?GatewayPayment
    {
        $type = (string) $request->input('event_type');
        $id = (string) $request->input('resource.id');

        if ($id === '' || ! in_array($type, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED'], true)) {
            return null;
        }

        $this->assertSignature($account, $request);

        // Un cobro ya capturado dice a qué orden de PayPal pertenece; lo demás se lee de esa orden
        $orderId = $type === 'CHECKOUT.ORDER.APPROVED'
            ? $id
            : $this->send(fn () => $this->client($account)->get("/v2/payments/captures/{$id}"))->json('supplementary_data.related_ids.order_id');

        if (! is_string($orderId) || $orderId === '') {
            return null;
        }

        $order = $this->send(fn () => $this->client($account)->get("/v2/checkout/orders/{$orderId}"));

        if ($order->status() === 404) {
            return null;
        }

        $this->assertOk($order);

        return $this->paymentsOf($account, $order->json())[0] ?? null;
    }

    public function refund(GatewayCredentials $account, string $paymentId): string
    {
        // Sin importe en el cuerpo, PayPal devuelve la captura completa
        $response = $this->send(fn () => $this->client($account)
            ->withHeaders(['PayPal-Request-Id' => "refund-{$paymentId}"])
            ->withBody('{}', 'application/json')
            ->post("/v2/payments/captures/{$paymentId}/refund"));

        if ($response->clientError() && $response->status() !== 401) {
            throw new ApiException(
                ErrorCode::GatewayError,
                'PayPal no aceptó la devolución'.(($reason = $response->json('details.0.description') ?? $response->json('message')) ? " ({$reason})" : '').'. Revisa el pago en tu cuenta de PayPal.',
                502,
                ['gateway_status' => $response->status()]
            );
        }

        $this->assertOk($response);

        return (string) $response->json('id');
    }

    /**
     * Los cobros de una orden de PayPal. Si quien paga ya la aprobó y falta
     * capturarla, se captura aquí: sin ese paso PayPal no mueve el dinero.
     *
     * @param  array<string, mixed>  $order
     * @return list<GatewayPayment>
     */
    private function paymentsOf(GatewayCredentials $account, array $order): array
    {
        if (($order['status'] ?? null) === 'APPROVED') {
            $capture = $this->send(fn () => $this->client($account)
                // Capturar dos veces la misma orden devuelve el mismo resultado
                ->withHeaders(['PayPal-Request-Id' => 'capture-'.$order['id']])
                ->withBody('{}', 'application/json')
                ->post("/v2/checkout/orders/{$order['id']}/capture"));

            // 422: otra petición la capturó en el mismo instante; se vuelve a leer
            $order = $capture->status() === 422
                ? $this->send(fn () => $this->client($account)->get("/v2/checkout/orders/{$order['id']}"))->json()
                : tap($capture, fn (Response $response) => $this->assertOk($response))->json();
        }

        $unit = $order['purchase_units'][0] ?? [];

        return array_map(fn (array $capture) => new GatewayPayment(
            id: (string) $capture['id'],
            status: match ($capture['status'] ?? null) {
                'COMPLETED' => GatewayPayment::APPROVED,
                'REFUNDED', 'PARTIALLY_REFUNDED' => GatewayPayment::REFUNDED,
                'DECLINED', 'FAILED' => GatewayPayment::REJECTED,
                default => GatewayPayment::PENDING,
            },
            amount: number_format((float) ($capture['amount']['value'] ?? 0), 2, '.', ''),
            currency: (string) ($capture['amount']['currency_code'] ?? ''),
            orderReference: $capture['custom_id'] ?? $unit['custom_id'] ?? $unit['reference_id'] ?? null,
            method: Payment::METHOD_PAYPAL,
            paidAt: isset($capture['create_time']) ? Carbon::parse($capture['create_time'])->utc() : null,
            checkoutId: (string) ($order['id'] ?? ''),
        ), $unit['payments']['captures'] ?? []);
    }

    /**
     * Con el identificador del webhook guardado, PayPal confirma que el aviso
     * es suyo; si no lo confirma, se rechaza. Sin él se acepta: de todos
     * modos el pago se lee de PayPal con la cuenta.
     */
    private function assertSignature(GatewayCredentials $account, Request $request): void
    {
        $webhookId = $account->get('webhook_id');

        if ($webhookId === null || $webhookId === '') {
            return;
        }

        $response = $this->send(fn () => $this->client($account)->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('paypal-auth-algo'),
            'cert_url' => $request->header('paypal-cert-url'),
            'transmission_id' => $request->header('paypal-transmission-id'),
            'transmission_sig' => $request->header('paypal-transmission-sig'),
            'transmission_time' => $request->header('paypal-transmission-time'),
            'webhook_id' => $webhookId,
            'webhook_event' => $request->json()->all(),
        ]));

        if ($response->json('verification_status') !== 'SUCCESS') {
            throw new ApiException(ErrorCode::Forbidden, 'La firma del aviso no es válida.', 403);
        }
    }

    private function baseUrl(bool $sandbox): string
    {
        return config($sandbox ? 'payments.paypal.sandbox_url' : 'payments.paypal.live_url');
    }

    private function http(bool $sandbox): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($sandbox))
            ->acceptJson()
            ->withOptions(array_filter(['verify' => config('payments.ca_bundle')]))
            ->timeout(config('payments.timeout'));
    }

    private function tokenRequest(string $clientId, string $secret, bool $sandbox): Response
    {
        return $this->http($sandbox)->withBasicAuth($clientId, $secret)->asForm()->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);
    }

    /**
     * Cliente ya identificado. PayPal entrega un token temporal a cambio de
     * las claves: se reutiliza mientras dure.
     */
    private function client(GatewayCredentials $account): PendingRequest
    {
        $clientId = (string) $account->get('client_id');
        $secret = (string) $account->get('client_secret');
        $key = 'paypal_token_'.hash('sha256', $clientId.'|'.$secret.'|'.(int) $account->test);

        $token = Cache::get($key);

        if ($token === null) {
            $response = $this->tokenRequest($clientId, $secret, $account->test);
            $this->assertOk($response);

            $token = (string) $response->json('access_token');
            Cache::put($key, $token, max(60, (int) $response->json('expires_in') - 120));
        }

        return $this->http($account->test)->withToken($token)->asJson();
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
            Log::warning('PayPal no respondió: '.$e->getMessage());

            throw new ApiException(ErrorCode::GatewayError, 'PayPal no respondió. Inténtalo de nuevo en unos minutos.', 502);
        }
    }

    private function assertOk(Response $response): void
    {
        if ($response->failed()) {
            throw new ApiException(
                ErrorCode::GatewayError,
                $response->status() === 401
                    ? 'PayPal rechazó las claves guardadas. Hay que volver a conectar la cuenta.'
                    : 'PayPal no pudo atender la operación. Inténtalo de nuevo en unos minutos.',
                502,
                ['gateway_status' => $response->status()]
            );
        }
    }
}
