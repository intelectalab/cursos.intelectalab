<?php

namespace Tests\Feature\Sales;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * S6: cobro en línea con PayPal, que cobra en dólares. Lo que está en soles
 * se convierte con el tipo de cambio que fija quien cobra. PayPal se simula.
 */
class PayPalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const PP = 'https://api-m.sandbox.paypal.com';

    private Tenant $tenant;

    private string $admin;

    private string $buyer;

    private string $course;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        Http::preventStrayRequests();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $admin = fn () => $this->withToken($this->admin);
        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 120])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->buyer = $this->login('acme', 'ana@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * Lo que PayPal responderá desde ahora (reemplaza lo simulado antes). El
     * token de acceso se entrega siempre.
     *
     * @param  array<string, mixed>  $responses  por ruta de su API
     */
    private function payPal(array $responses = []): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(collect($responses + ['/v1/oauth2/token' => Http::response(['access_token' => 'token-temporal', 'expires_in' => 3600])])
            ->mapWithKeys(fn ($response, string $path) => [self::PP.$path => $response])->all());
    }

    /**
     * La academia conecta su aplicación de pruebas de PayPal y fija su tipo de cambio.
     *
     * @param  array<string, mixed>  $extra
     */
    private function connect(array $extra = [], ?float $rate = 3.75): void
    {
        $this->payPal();
        $gateway = $this->api('/settings/payment-gateways/paypal');

        $this->withToken($this->admin)->putJson($gateway, ['client_id' => 'cliente-de-la-academia', 'client_secret' => 'secreto-de-la-academia', 'sandbox' => true] + $extra)->assertOk();

        if ($rate !== null) {
            $this->withToken($this->admin)->putJson($gateway, ['exchange_rate' => $rate])->assertOk();
        }
    }

    private function order(): string
    {
        return $this->withToken($this->buyer)->postJson($this->api('/orders'), ['course_id' => $this->course])->assertCreated()->json('data.id');
    }

    /**
     * Orden de PayPal tal como la devuelve su API.
     *
     * @return array<string, mixed>
     */
    private function ppOrder(string $order, string $status, string $amount = '32.00', string $currency = 'USD'): array
    {
        return [
            'id' => 'PP-ORDER-1',
            'status' => $status,
            'purchase_units' => [[
                'reference_id' => $order,
                'custom_id' => $order,
                'payments' => $status === 'COMPLETED' ? ['captures' => [[
                    'id' => 'CAPTURA-77',
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => $currency, 'value' => $amount],
                    'custom_id' => $order,
                    'create_time' => '2026-10-08T20:00:00Z',
                ]]] : [],
            ]],
        ];
    }

    private function startCheckout(string $order): void
    {
        $this->payPal(['/v2/checkout/orders' => Http::response(['id' => 'PP-ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
            ['rel' => 'self', 'href' => self::PP.'/v2/checkout/orders/PP-ORDER-1'],
            ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PP-ORDER-1'],
        ]], 200)]);

        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'PAYPAL', 'return_url' => "https://acme.localhost/my-orders/{$order}"])
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=PP-ORDER-1');
    }

    public function test_paypal_is_offered_in_dollars_only_once_the_organization_sets_its_exchange_rate(): void
    {
        $gateways = $this->api('/settings/payment-gateways');

        // Claves que PayPal no reconoce no se guardan
        $this->payPal(['/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401)]);
        $this->withToken($this->admin)->putJson("{$gateways}/paypal", ['client_id' => 'malo', 'client_secret' => 'malo', 'sandbox' => true])
            ->assertStatus(422)->assertJsonStructure(['details' => ['client_id']]);

        $this->connect(rate: null);
        $response = $this->withToken($this->admin)->getJson($gateways)->assertOk()
            ->assertJsonPath('data.1.code', 'PAYPAL')
            ->assertJsonPath('data.1.connected', true)
            ->assertJsonPath('data.1.account.test', true)
            ->assertJsonPath('data.1.needs_exchange_rate', true)
            ->assertJsonPath('data.1.exchange_rate', null);
        $this->assertStringNotContainsString('secreto-de-la-academia', $response->getContent());

        // Sin tipo de cambio no se puede cobrar en dólares lo que está en soles: no se ofrece
        $order = $this->order();
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonCount(0, 'data.online_gateways');

        $this->withToken($this->admin)->putJson("{$gateways}/paypal", ['exchange_rate' => 0])->assertStatus(422);
        $this->withToken($this->admin)->putJson("{$gateways}/paypal", ['exchange_rate' => 3.75])->assertOk()->assertJsonPath('data.1.exchange_rate', 3.75);

        // El comprador ve cuánto pagará en dólares antes de ir a PayPal: S/ 120 a 3.75 = US$ 32
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))
            ->assertJsonPath('data.online_gateways.0.code', 'PAYPAL')
            ->assertJsonPath('data.online_gateways.0.charge.currency', 'USD')
            ->assertJsonPath('data.online_gateways.0.charge.amount', '32.00');

        // Volver a conectar las claves no borra el tipo de cambio
        $this->connect(rate: null);
        $this->withToken($this->admin)->getJson($gateways)->assertJsonPath('data.1.exchange_rate', 3.75);
    }

    public function test_a_buyer_approves_in_paypal_and_the_charge_is_captured_when_they_return(): void
    {
        $this->connect();
        $order = $this->order();
        $this->startCheckout($order);

        // A PayPal se le pide cobrar el equivalente en dólares, a nombre de la orden, en su entorno de pruebas
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::PP.'/v2/checkout/orders'
            && $request->hasHeader('Authorization', 'Bearer token-temporal')
            && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '32.00']
            && $request['purchase_units'][0]['custom_id'] === $order
            && $request['payment_source']['paypal']['experience_context']['return_url'] === "https://acme.localhost/my-orders/{$order}");

        $sync = fn () => $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay/sync"));

        // Volvió sin aprobar el cobro: no hay nada que capturar
        $this->payPal(['/v2/checkout/orders/PP-ORDER-1' => Http::response($this->ppOrder($order, 'PAYER_ACTION_REQUIRED'))]);
        $sync()->assertOk()->assertJsonPath('data.status', 'PENDING');

        // Lo aprobó: se captura y la orden queda pagada
        $this->payPal([
            '/v2/checkout/orders/PP-ORDER-1/capture' => Http::response($this->ppOrder($order, 'COMPLETED'), 201),
            '/v2/checkout/orders/PP-ORDER-1' => Http::response($this->ppOrder($order, 'APPROVED')),
        ]);
        $sync()->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payment.gateway', 'PAYPAL')
            ->assertJsonPath('data.payment.method', 'PAYPAL')
            ->assertJsonPath('data.payment.reference', 'CAPTURA-77')
            // La orden sigue siendo en soles; queda anotado lo que se cobró en dólares
            ->assertJsonPath('data.payment.amount', '120.00')
            ->assertJsonPath('data.payment.notes', 'Cobrado USD 32.00 (tipo de cambio 3.7500 soles por dólar).')
            ->assertJsonPath('data.items.0.enrolled', true);
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::PP.'/v2/checkout/orders/PP-ORDER-1/capture' && $request->method() === 'POST');
    }

    public function test_a_paypal_notification_settles_the_order_and_a_wrong_amount_is_left_for_review(): void
    {
        $this->connect();
        $order = $this->order();
        $this->startCheckout($order);

        // El aviso solo dispara la consulta: lo que vale es lo que PayPal responde por la orden
        $this->payPal([
            '/v2/payments/captures/CAPTURA-77' => Http::response(['id' => 'CAPTURA-77', 'supplementary_data' => ['related_ids' => ['order_id' => 'PP-ORDER-1']]]),
            '/v2/checkout/orders/PP-ORDER-1' => Http::response($this->ppOrder($order, 'COMPLETED', '3.20')),
        ]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $notify = fn () => $this->postJson($this->api('/payments/webhooks/paypal'), ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTURA-77']]);

        // PayPal cobró menos de lo pedido: no se aplica y queda para revisar
        $notify()->assertOk();
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.payment_reviews.0.gateway', 'PAYPAL')
            ->assertJsonPath('data.payment_reviews.0.payment_id', 'CAPTURA-77');

        // Un aviso de otra cosa se recibe y no hace nada
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->api('/payments/webhooks/paypal'), ['event_type' => 'BILLING.PLAN.CREATED', 'resource' => ['id' => 'X']])->assertOk();

        // En otra orden, el importe correcto la deja pagada, y el aviso repetido no duplica
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/cancel"))->assertOk();
        $second = $this->order();
        $this->startCheckout($second);
        $this->payPal([
            '/v2/payments/captures/CAPTURA-77' => Http::response(['id' => 'CAPTURA-77', 'supplementary_data' => ['related_ids' => ['order_id' => 'PP-ORDER-1']]]),
            '/v2/checkout/orders/PP-ORDER-1' => Http::response($this->ppOrder($second, 'COMPLETED')),
        ]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $notify()->assertOk();
        $notify()->assertOk();

        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$second}"))->assertJsonPath('data.status', 'PAID');
        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => $this->assertSame(1, Payment::query()->count()));
    }

    public function test_with_the_webhook_id_saved_paypal_must_vouch_for_each_notification(): void
    {
        $this->connect(['webhook_id' => 'WH-123']);
        $order = $this->order();
        $this->startCheckout($order);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $notify = fn () => $this->postJson($this->api('/payments/webhooks/paypal'), ['event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'PP-ORDER-1']], ['paypal-transmission-id' => 't-1']);

        $this->payPal(['/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'])]);
        $notify()->assertForbidden();
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.status', 'PENDING');

        // PayPal confirma que el aviso es suyo: la orden aprobada se captura
        $this->payPal([
            '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '/v2/checkout/orders/PP-ORDER-1/capture' => Http::response($this->ppOrder($order, 'COMPLETED'), 201),
            '/v2/checkout/orders/PP-ORDER-1' => Http::response($this->ppOrder($order, 'APPROVED')),
        ]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $notify()->assertOk();
        Http::assertSent(fn (GatewayRequest $request) => str_ends_with($request->url(), '/verify-webhook-signature') && $request['webhook_id'] === 'WH-123' && $request['transmission_id'] === 't-1');

        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.status', 'PAID');
    }

    public function test_an_organization_pays_its_plan_with_paypal_in_dollars(): void
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);
        $platform = $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->json('data.token');
        $plan = $this->withToken($platform)->postJson($this->centralUrl('/platform/plans'), [
            'name' => 'Hasta 50 alumnos', 'price' => 99, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 50,
        ])->assertCreated()->json('data.id');

        // La plataforma conecta su propia cuenta de PayPal y fija su tipo de cambio
        $this->payPal();
        $gateway = $this->centralUrl('/platform/payment-gateways/paypal');
        $this->withToken($platform)->putJson($gateway, ['client_id' => 'cliente-de-la-plataforma', 'client_secret' => 'secreto-de-la-plataforma', 'sandbox' => true])
            ->assertOk()->assertJsonPath('data.1.needs_exchange_rate', true);
        $this->withToken($platform)->putJson($gateway, ['exchange_rate' => 3.75])->assertOk()->assertJsonPath('data.1.exchange_rate', 3.75);

        $admin = $this->login('acme', 'admin@acme.com');
        $order = $this->withToken($admin)->postJson($this->api('/subscription/orders'), ['plan_id' => $plan])->assertCreated()->json('data.id');

        // S/ 99 a 3.75 = US$ 26.40
        $this->withToken($admin)->getJson($this->api('/subscription/plans'))
            ->assertJsonPath('data.online_gateways.0.code', 'PAYPAL')
            ->assertJsonPath('data.online_gateways.0.charge.amount', '26.40');

        $this->payPal(['/v2/checkout/orders' => Http::response(['id' => 'PP-ORDER-1', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PP-ORDER-1']]])]);
        $this->withToken($admin)->postJson($this->api("/subscription/orders/{$order}/pay"), ['gateway' => 'PAYPAL', 'return_url' => 'https://acme.localhost/settings/plan'])->assertCreated();
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::PP.'/v2/checkout/orders' && $request['purchase_units'][0]['amount']['value'] === '26.40');

        $this->payPal([
            '/v2/checkout/orders/PP-ORDER-1/capture' => Http::response($this->ppOrder($order, 'COMPLETED', '26.40'), 201),
            '/v2/checkout/orders/PP-ORDER-1' => Http::response($this->ppOrder($order, 'APPROVED', '26.40')),
        ]);
        $this->withToken($admin)->postJson($this->api("/subscription/orders/{$order}/pay/sync"))
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.paid_online', true)
            ->assertJsonPath('data.payment_method', 'PAYPAL')
            ->assertJsonPath('data.notes', 'Cobrado USD 26.40 con PayPal.');

        $this->withToken($admin)->getJson($this->api('/subscription'))
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.plan.name', 'Hasta 50 alumnos');
    }
}
