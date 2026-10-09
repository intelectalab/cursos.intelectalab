<?php

namespace Tests\Feature\Sales;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Sales\Domain\Models\GatewayAccount;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Sales\Domain\Models\PaymentAttempt;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * S6: cobro en línea con Mercado Pago. La pasarela se simula: lo que se
 * prueba es qué se le pide y qué se hace con lo que responde.
 */
class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const MP = 'https://api.mercadopago.com';

    private Tenant $tenant;

    private string $admin;

    private string $buyer;

    private string $course;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * Lo que Mercado Pago responderá desde ahora (reemplaza lo simulado antes).
     *
     * @param  array<string, mixed>  $responses  por ruta de su API
     */
    private function mercadoPago(array $responses): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(collect($responses)->mapWithKeys(fn ($response, string $path) => [self::MP.$path => $response])->all());
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * La academia conecta su cuenta de Mercado Pago.
     *
     * @param  array<string, mixed>  $extra
     */
    private function connect(array $extra = []): void
    {
        $this->mercadoPago(['/users/me' => Http::response(['id' => 99, 'nickname' => 'ACADEMIAACME', 'site_id' => 'MPE'])]);

        $this->withToken($this->admin)->putJson($this->api('/settings/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave-de-la-academia'] + $extra)->assertOk();
    }

    private function order(): string
    {
        return $this->withToken($this->buyer)->postJson($this->api('/orders'), ['course_id' => $this->course])->assertCreated()->json('data.id');
    }

    /**
     * Pago tal como lo devuelve Mercado Pago.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mpPayment(string $order, array $overrides = []): array
    {
        return $overrides + [
            'id' => 555001,
            'status' => 'approved',
            'transaction_amount' => 120,
            'currency_id' => 'PEN',
            'external_reference' => $order,
            'payment_method_id' => 'yape',
            'payment_type_id' => 'debit_card',
            'date_approved' => '2026-10-08T15:00:00.000-04:00',
        ];
    }

    private function startCheckout(string $order): void
    {
        $this->mercadoPago(['/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=PREF-1', 'sandbox_init_point' => 'https://sandbox.mercadopago.com.pe/x'], 201)]);

        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => "https://acme.localhost/my-orders/{$order}"])
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=PREF-1');
    }

    public function test_an_organization_connects_its_account_and_its_keys_never_come_back(): void
    {
        $gateways = $this->api('/settings/payment-gateways');

        $this->withToken($this->admin)->getJson($gateways)
            ->assertOk()
            ->assertJsonPath('data.0.code', 'MERCADOPAGO')
            ->assertJsonPath('data.0.connected', false)
            ->assertJsonPath('data.0.currency_supported', true);

        // Una clave que Mercado Pago no reconoce no se guarda
        $this->mercadoPago(['/users/me' => Http::response(['message' => 'invalid_token'], 401)]);
        $this->withToken($this->admin)->putJson("{$gateways}/mercadopago", ['access_token' => 'mala'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['details' => ['access_token']]);

        $this->mercadoPago(['/users/me' => Http::response(['id' => 99, 'nickname' => 'ACADEMIAACME', 'site_id' => 'MPE'])]);
        $response = $this->withToken($this->admin)->putJson("{$gateways}/mercadopago", ['access_token' => 'APP_USR-clave-de-la-academia', 'webhook_secret' => 'firma-secreta'])
            ->assertOk()
            ->assertJsonPath('data.0.connected', true)
            ->assertJsonPath('data.0.enabled', true)
            ->assertJsonPath('data.0.account.name', 'ACADEMIAACME')
            ->assertJsonPath('data.0.has_webhook_secret', true);

        // Ni en la respuesta, ni en claro en la base, ni en la auditoría
        $this->assertStringNotContainsString('APP_USR-clave-de-la-academia', $response->getContent());
        $this->assertStringNotContainsString('firma-secreta', $response->getContent());
        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertStringNotContainsString('APP_USR', GatewayAccount::query()->firstOrFail()->getRawOriginal('credentials'));
            $this->assertStringNotContainsString('APP_USR', (string) json_encode(AuditEvent::query()->where('action', 'payment_gateways.connected')->firstOrFail()->after));
        });

        // Pausar deja la cuenta conectada pero sin ofrecerla; desconectar la borra
        $order = $this->order();
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.online_gateways.0.code', 'MERCADOPAGO');
        $this->withToken($this->admin)->putJson("{$gateways}/mercadopago", ['enabled' => false])->assertOk()->assertJsonPath('data.0.enabled', false);
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonCount(0, 'data.online_gateways');
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => "https://acme.localhost/my-orders/{$order}"])
            ->assertStatus(409)->assertJsonPath('code', 'GATEWAY_NOT_AVAILABLE');
        $this->withToken($this->admin)->deleteJson("{$gateways}/mercadopago")->assertOk()->assertJsonPath('data.0.connected', false);

        // Un alumno no toca las cuentas de cobro
        $this->withToken($this->buyer)->getJson($gateways)->assertForbidden();
    }

    public function test_a_buyer_is_sent_to_mercado_pago_and_the_notification_settles_the_order(): void
    {
        $this->connect();
        $order = $this->order();
        $this->startCheckout($order);

        // A Mercado Pago se le pide cobrar el total de la orden, a nombre de ella, con la clave de la academia
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::MP.'/checkout/preferences'
            && $request->hasHeader('Authorization', 'Bearer APP_USR-clave-de-la-academia')
            && $request['external_reference'] === $order
            && $request['items'][0]['unit_price'] == 120
            && $request['items'][0]['currency_id'] === 'PEN'
            && $request['notification_url'] === 'https://acme.localhost/api/v1/payments/webhooks/mercadopago'
            && $request['back_urls']['success'] === "https://acme.localhost/my-orders/{$order}");

        // Ir a pagar no paga: la orden sigue pendiente
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.status', 'PENDING');

        // Llega el aviso, sin sesión; el pago se consulta en Mercado Pago
        $this->mercadoPago(['/v1/payments/555001' => Http::response($this->mpPayment($order))]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $notify = fn () => $this->postJson($this->api('/payments/webhooks/mercadopago?data.id=555001&type=payment'), ['type' => 'payment', 'data' => ['id' => '555001']]);
        $notify()->assertOk();

        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payment.gateway', 'MERCADOPAGO')
            ->assertJsonPath('data.payment.method', 'YAPE')
            ->assertJsonPath('data.payment.reference', '555001')
            ->assertJsonPath('data.payment.confirmed_by', null)
            ->assertJsonPath('data.items.0.enrolled', true);
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertOk();

        // El mismo aviso repetido no cobra dos veces
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $notify()->assertOk();
        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(PaymentAttempt::STATUS_APPROVED, PaymentAttempt::query()->firstOrFail()->status);
        });
    }

    public function test_returning_from_mercado_pago_checks_the_payment_without_waiting_for_the_notification(): void
    {
        $this->connect();
        $order = $this->order();
        $this->startCheckout($order);
        $sync = fn () => $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay/sync"));

        // Todavía no pagó
        $this->mercadoPago(['/v1/payments/search*' => Http::response(['results' => []])]);
        $sync()->assertOk()->assertJsonPath('data.status', 'PENDING');

        // Un pago rechazado no cambia la orden
        $this->mercadoPago(['/v1/payments/search*' => Http::sequence()
            ->push(['results' => [$this->mpPayment($order, ['status' => 'rejected'])]])
            ->push(['results' => [$this->mpPayment($order, ['payment_method_id' => 'visa', 'payment_type_id' => 'credit_card'])]])]);
        $sync()->assertOk()->assertJsonPath('data.status', 'PENDING');

        $sync()->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payment.method', 'CARD')
            ->assertJsonPath('data.online_gateways', []);

        // La orden de otra persona no existe para quien pregunta
        $other = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'beto@acme.com']);
        $this->withToken($this->login('acme', $other->email))->postJson($this->api("/me/orders/{$order}/pay/sync"))->assertNotFound();
    }

    public function test_a_charge_that_does_not_match_the_order_is_left_for_a_person_to_review(): void
    {
        $this->connect();
        $order = $this->order();
        $this->startCheckout($order);

        // Mercado Pago informa un importe distinto al de la orden
        $this->mercadoPago(['/v1/payments/search*' => Http::response(['results' => [$this->mpPayment($order, ['transaction_amount' => 12])]])]);
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay/sync"))->assertOk()->assertJsonPath('data.status', 'PENDING');

        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.payment_reviews.0.gateway', 'MERCADOPAGO')
            ->assertJsonPath('data.payment_reviews.0.payment_id', '555001');

        // La orden se cancela y después llega un pago por el importe correcto: no revive la orden
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/cancel"))->assertOk();
        $this->mercadoPago(['/v1/payments/777' => Http::response($this->mpPayment($order, ['id' => 777]))]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->api('/payments/webhooks/mercadopago?data.id=777&type=payment'))->assertOk();

        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.payment', null);
    }

    public function test_notifications_are_checked_and_never_trusted(): void
    {
        $this->connect(['webhook_secret' => 'firma-secreta']);
        $order = $this->order();
        $this->startCheckout($order);
        $this->mercadoPago(['/v1/payments/555001' => Http::response($this->mpPayment($order))]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $url = $this->api('/payments/webhooks/mercadopago?data.id=555001&type=payment');

        // Con la clave de firma guardada, un aviso sin firma válida se rechaza
        $this->postJson($url, [], ['x-request-id' => 'req-1', 'x-signature' => 'ts=1700000000,v1=falsa'])->assertForbidden();
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.status', 'PENDING');

        // Un aviso que no es de un pago, o de un pago ajeno, se recibe y no hace nada
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->api('/payments/webhooks/mercadopago?id=1&topic=merchant_order'))->assertOk();
        $this->postJson($this->api('/payments/webhooks/otra'))->assertNotFound();

        $signature = hash_hmac('sha256', 'id:555001;request-id:req-1;ts:1700000000;', 'firma-secreta');
        $this->postJson($url, [], ['x-request-id' => 'req-1', 'x-signature' => "ts=1700000000,v1={$signature}"])->assertOk();
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.status', 'PAID');
    }

    public function test_the_buyer_is_only_sent_back_to_the_same_organization(): void
    {
        $this->connect();
        $order = $this->order();

        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => 'https://sitio-ajeno.com/robo'])
            ->assertStatus(422)->assertJsonStructure(['details' => ['return_url']]);

        // Si Mercado Pago falla, se le dice al comprador y la orden queda como estaba
        $this->mercadoPago(['/checkout/preferences' => Http::response(['message' => 'internal_error'], 500)]);
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => "http://acme.localhost:5173/my-orders/{$order}"])
            ->assertStatus(502)->assertJsonPath('code', 'GATEWAY_ERROR');
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.status', 'PENDING');
    }
}
