<?php

namespace Tests\Feature\Sales;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Billing\Mail\PlanOrderMail;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
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
 * S6: reembolso total. De una compra (pagada por fuera o en línea) y del
 * pago en línea de un plan. Las pasarelas se simulan.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private const MP = 'https://api.mercadopago.com';

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
        $this->course = $this->publishedCourse(['price' => 120]);

        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->buyer = $this->login('acme', 'ana@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publishedCourse(array $attributes): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), $attributes + ['title' => 'Excel para finanzas', 'language' => 'es'])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    /**
     * Lo que las pasarelas responderán desde ahora (reemplaza lo simulado antes).
     *
     * @param  array<string, mixed>  $responses  por dirección completa
     */
    private function gateways(array $responses = []): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake($responses + [self::PP.'/v1/oauth2/token' => Http::response(['access_token' => 'token-temporal', 'expires_in' => 3600])]);
    }

    private function order(?string $course = null): string
    {
        return $this->withToken($this->buyer)->postJson($this->api('/orders'), ['course_id' => $course ?? $this->course])->assertCreated()->json('data.id');
    }

    /**
     * Orden pagada con Mercado Pago (pago n.º 555001).
     */
    private function orderPaidWithMercadoPago(): string
    {
        $this->gateways([self::MP.'/users/me' => Http::response(['id' => 99, 'nickname' => 'ACADEMIAACME', 'site_id' => 'MPE'])]);
        $this->withToken($this->admin)->putJson($this->api('/settings/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave-de-la-academia'])->assertOk();

        $order = $this->order();
        $this->gateways([self::MP.'/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-1'], 201)]);
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => "https://acme.localhost/my-orders/{$order}"])->assertCreated();

        $this->gateways([self::MP.'/v1/payments/search*' => Http::response(['results' => [[
            'id' => 555001, 'status' => 'approved', 'transaction_amount' => 120, 'currency_id' => 'PEN', 'external_reference' => $order, 'payment_type_id' => 'credit_card',
        ]]])]);
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay/sync"))->assertOk()->assertJsonPath('data.status', 'PAID');

        return $order;
    }

    public function test_a_purchase_paid_outside_is_refunded_and_the_buyer_loses_the_enrollment(): void
    {
        $order = $this->order();
        $refund = $this->api("/orders/{$order}/refund");

        // Solo se reembolsa lo pagado, con motivo, y quien tiene el permiso
        $this->withToken($this->admin)->postJson($refund, ['reason' => 'El alumno desistió de la compra.'])->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_REFUNDABLE');
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER', 'reference' => 'OP-1'])->assertOk();
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertOk();
        $this->withToken($this->admin)->postJson($refund, [])->assertStatus(422);
        $this->withToken($this->buyer)->postJson($refund, ['reason' => 'Quiero mi dinero de vuelta.'])->assertForbidden();

        $this->withToken($this->admin)->postJson($refund, ['reason' => 'El alumno desistió de la compra.', 'reference' => 'Transferencia de devolución OP-99'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REFUNDED')
            ->assertJsonPath('data.items.0.enrolled', true)
            ->assertJsonPath('data.payment.refund.reason', 'El alumno desistió de la compra.')
            ->assertJsonPath('data.payment.refund.reference', 'Transferencia de devolución OP-99')
            ->assertJsonPath('data.payment.refund.by', 'Ada Admin');

        // Pierde el acceso al curso, lo ve en su orden y puede volver a comprar
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertNotFound();
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.status', 'REFUNDED');
        $this->order();

        // No se reembolsa dos veces, y queda en la lista y en la auditoría
        $this->withToken($this->admin)->postJson($refund, ['reason' => 'Otra vez, por error.'])->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_REFUNDABLE');
        $this->withToken($this->admin)->getJson($this->api('/orders?status=REFUNDED'))->assertJsonCount(1, 'data')->assertJsonPath('meta.counts.REFUNDED', 1);
        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => $this->assertSame(1, AuditEvent::query()->where('action', 'orders.refunded')->count()));

        // Una inscripción gratuita no tiene nada que devolver
        $free = $this->order($this->publishedCourse(['title' => 'Gratis', 'price' => 0]));
        $this->withToken($this->admin)->postJson($this->api("/orders/{$free}/refund"), ['reason' => 'Se inscribió por error.'])->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_REFUNDABLE');
    }

    public function test_a_purchase_paid_online_is_refunded_through_the_gateway_first(): void
    {
        $order = $this->orderPaidWithMercadoPago();
        $refund = fn () => $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'Cobro duplicado al alumno.']);

        // Si Mercado Pago no devuelve, la orden queda pagada y se explica por qué
        $this->gateways([self::MP.'/v1/payments/555001/refunds' => Http::response(['message' => 'The payment is too old to be refunded'], 400)]);
        $refund()->assertStatus(502)->assertJsonPath('code', 'GATEWAY_ERROR');
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))->assertJsonPath('data.status', 'PAID');
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertOk();

        $this->gateways([self::MP.'/v1/payments/555001/refunds' => Http::response(['id' => 880022, 'status' => 'approved'], 201)]);
        $refund()->assertOk()
            ->assertJsonPath('data.status', 'REFUNDED')
            ->assertJsonPath('data.payment.gateway', 'MERCADOPAGO')
            ->assertJsonPath('data.payment.refund.reference', '880022');

        // Se devolvió con la cuenta de la academia y de forma que repetir no devuelva dos veces
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::MP.'/v1/payments/555001/refunds'
            && $request->hasHeader('Authorization', 'Bearer APP_USR-clave-de-la-academia')
            && $request->hasHeader('X-Idempotency-Key', 'refund-555001'));
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertNotFound();
    }

    public function test_an_online_refund_needs_the_account_and_a_refund_made_in_the_gateway_panel_is_flagged(): void
    {
        $order = $this->orderPaidWithMercadoPago();

        // Alguien devolvió el pago en el panel de Mercado Pago: llega el aviso y la orden queda marcada
        $this->gateways([self::MP.'/v1/payments/555001' => Http::response([
            'id' => 555001, 'status' => 'refunded', 'transaction_amount' => 120, 'currency_id' => 'PEN', 'external_reference' => $order,
        ])]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->api('/payments/webhooks/mercadopago?data.id=555001&type=payment'))->assertOk();

        $review = $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))->assertJsonPath('data.status', 'PAID')->json('data.payment_reviews.0.reason');
        $this->assertStringContainsString('se devolvió desde su panel', (string) $review);

        // Sin la cuenta conectada no hay con qué devolver
        $this->withToken($this->admin)->deleteJson($this->api('/settings/payment-gateways/mercadopago'))->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'Cobro duplicado al alumno.'])
            ->assertStatus(409)->assertJsonPath('code', 'GATEWAY_NOT_AVAILABLE');
    }

    public function test_a_refund_rejected_for_inactive_production_credentials_says_so_and_changes_nothing(): void
    {
        $order = $this->orderPaidWithMercadoPago();

        // La clave sirve para cobrar y consultar, pero Mercado Pago no deja devolver con credenciales de producción sin activar
        $this->gateways([self::MP.'/v1/payments/555001/refunds' => Http::response([
            'message' => 'Unauthorized use of live credentials', 'error' => 'unauthorized', 'status' => 401,
        ], 401)]);

        $message = $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'El alumno pidió su devolución.'])
            ->assertStatus(502)->assertJsonPath('code', 'GATEWAY_ERROR')->json('message');

        $this->assertStringContainsString('credenciales de producción', (string) $message);
        $this->assertStringNotContainsString('volver a conectar', (string) $message);
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))->assertJsonPath('data.status', 'PAID');
    }

    public function test_a_purchase_paid_with_paypal_refunds_the_capture(): void
    {
        $this->gateways();
        $gateway = $this->api('/settings/payment-gateways/paypal');
        $this->withToken($this->admin)->putJson($gateway, ['client_id' => 'cliente', 'client_secret' => 'secreto', 'sandbox' => true])->assertOk();
        $this->withToken($this->admin)->putJson($gateway, ['exchange_rate' => 3.75])->assertOk();

        $order = $this->order();
        $this->gateways([self::PP.'/v2/checkout/orders' => Http::response(['id' => 'PP-1', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PP-1']]])]);
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'PAYPAL', 'return_url' => "https://acme.localhost/my-orders/{$order}"])->assertCreated();
        $this->gateways([self::PP.'/v2/checkout/orders/PP-1' => Http::response(['id' => 'PP-1', 'status' => 'COMPLETED', 'purchase_units' => [[
            'custom_id' => $order,
            'payments' => ['captures' => [['id' => 'CAPTURA-77', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '32.00'], 'custom_id' => $order]]],
        ]]])]);
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay/sync"))->assertOk()->assertJsonPath('data.status', 'PAID');

        $this->gateways([self::PP.'/v2/payments/captures/CAPTURA-77/refund' => Http::response(['id' => 'DEVOLUCION-5', 'status' => 'COMPLETED'], 201)]);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'El alumno pidió su devolución.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REFUNDED')
            ->assertJsonPath('data.payment.refund.reference', 'DEVOLUCION-5');
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::PP.'/v2/payments/captures/CAPTURA-77/refund' && $request->hasHeader('PayPal-Request-Id', 'refund-CAPTURA-77'));
    }

    public function test_the_platform_refunds_a_plan_paid_online_and_the_plan_goes_back(): void
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);
        $platform = $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->json('data.token');
        $plan = $this->withToken($platform)->postJson($this->centralUrl('/platform/plans'), [
            'name' => 'Hasta 50 alumnos', 'price' => 99, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 50,
        ])->assertCreated()->json('data.id');

        $this->gateways([self::MP.'/users/me' => Http::response(['id' => 1, 'nickname' => 'INTELECTALAB', 'site_id' => 'MPE'])]);
        $this->withToken($platform)->putJson($this->centralUrl('/platform/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave-de-la-plataforma'])->assertOk();

        // La academia paga su plan en línea
        $admin = $this->login('acme', 'admin@acme.com');
        $order = $this->withToken($admin)->postJson($this->api('/subscription/orders'), ['plan_id' => $plan])->assertCreated()->json('data.id');
        $this->gateways([self::MP.'/checkout/preferences' => Http::response(['id' => 'PREF-9', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-9'], 201)]);
        $this->withToken($admin)->postJson($this->api("/subscription/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => 'https://acme.localhost/settings/plan'])->assertCreated();
        $this->gateways([self::MP.'/v1/payments/search*' => Http::response(['results' => [[
            'id' => 900100, 'status' => 'approved', 'transaction_amount' => 99, 'currency_id' => 'PEN', 'external_reference' => $order, 'payment_type_id' => 'credit_card',
        ]]])]);
        $this->withToken($admin)->postJson($this->api("/subscription/orders/{$order}/pay/sync"))->assertOk()->assertJsonPath('data.status', 'PAID');

        $this->withToken($platform)->getJson($this->centralUrl('/platform/plan-orders?status=PAID'))
            ->assertJsonPath('data.0.can_refund', true)
            ->assertJsonPath('data.0.can_revert', false);

        // Se devuelve el dinero y el plan vuelve a la prueba; la orden queda cancelada con el motivo
        $this->gateways([self::MP.'/v1/payments/900100/refunds' => Http::response(['id' => 770011, 'status' => 'approved'], 201)]);
        $this->withToken($platform)->postJson($this->centralUrl("/platform/plan-orders/{$order}/refund"), ['reason' => 'La academia pagó dos veces.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.notes', 'Pago devuelto (devolución n.º 770011): La academia pagó dos veces.');
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::MP.'/v1/payments/900100/refunds' && $request->hasHeader('Authorization', 'Bearer APP_USR-clave-de-la-plataforma'));

        $this->withToken($admin)->getJson($this->api('/subscription'))
            ->assertJsonPath('data.state', 'TRIAL')
            ->assertJsonPath('meta.order_notice.status', 'CANCELLED');
        Mail::assertSent(PlanOrderMail::class, fn (PlanOrderMail $mail) => $mail->hasTo('admin@acme.com') && str_contains($mail->title, 'Se devolvió el pago'));

        // Ya devuelta, no se devuelve otra vez; y una confirmada a mano no pasa por la pasarela
        $this->gateways();
        $this->withToken($platform)->postJson($this->centralUrl("/platform/plan-orders/{$order}/refund"), ['reason' => 'Repetido por error.'])
            ->assertStatus(409)->assertJsonPath('code', 'PLAN_ORDER_NOT_REVERTIBLE');
        Http::assertNothingSent();
    }

    public function test_a_plan_refund_is_refused_before_touching_the_money_if_the_plan_changed_afterwards(): void
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);
        $platform = $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->json('data.token');
        $plan = $this->withToken($platform)->postJson($this->centralUrl('/platform/plans'), [
            'name' => 'Hasta 50 alumnos', 'price' => 99, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 50,
        ])->assertCreated()->json('data.id');
        $this->gateways([self::MP.'/users/me' => Http::response(['id' => 1, 'nickname' => 'INTELECTALAB', 'site_id' => 'MPE'])]);
        $this->withToken($platform)->putJson($this->centralUrl('/platform/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave-de-la-plataforma'])->assertOk();

        $admin = $this->login('acme', 'admin@acme.com');
        $order = $this->withToken($admin)->postJson($this->api('/subscription/orders'), ['plan_id' => $plan])->assertCreated()->json('data.id');
        $this->gateways([self::MP.'/checkout/preferences' => Http::response(['id' => 'PREF-9', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-9'], 201)]);
        $this->withToken($admin)->postJson($this->api("/subscription/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => 'https://acme.localhost/settings/plan'])->assertCreated();
        $this->gateways([self::MP.'/v1/payments/search*' => Http::response(['results' => [[
            'id' => 900100, 'status' => 'approved', 'transaction_amount' => 99, 'currency_id' => 'PEN', 'external_reference' => $order,
        ]]])]);
        $this->withToken($admin)->postJson($this->api("/subscription/orders/{$order}/pay/sync"))->assertOk()->assertJsonPath('data.status', 'PAID');

        // Después se le cambió el plan desde la ficha: devolver pisaría ese cambio
        $this->withToken($platform)->putJson($this->centralUrl("/platform/tenants/{$this->tenant->id}/subscription"), ['plan_id' => $plan, 'ends_at' => now()->addDays(90)->toDateString()])->assertOk();

        $this->gateways();
        $this->withToken($platform)->postJson($this->centralUrl("/platform/plan-orders/{$order}/refund"), ['reason' => 'La academia pidió su devolución.'])
            ->assertStatus(409)->assertJsonPath('code', 'PLAN_ORDER_NOT_REVERTIBLE');
        Http::assertNothingSent();
        $this->withToken($admin)->getJson($this->api('/subscription'))->assertJsonPath('data.state', 'ACTIVE');
    }
}
