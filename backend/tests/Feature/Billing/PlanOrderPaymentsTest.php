<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Billing\Mail\PlanOrderMail;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pago de las órdenes de plan: constancia adjunta, avisos, vencimiento,
 * deshacer una confirmación y cobro en línea con la cuenta de la plataforma.
 */
class PlanOrderPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const MP = 'https://api.mercadopago.com';

    private Tenant $alfa;

    private string $admin;

    private string $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake(config('files.disk'));

        $this->alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);

        $this->plan = $this->withToken($this->platform())->postJson($this->centralUrl('/platform/plans'), [
            'name' => 'Hasta 50 alumnos', 'price' => 99, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 50,
        ])->assertCreated()->json('data.id');

        $this->admin = $this->login('alfa', 'admin@alfa.com');
    }

    private function platform(): string
    {
        return $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->assertOk()->json('data.token');
    }

    private function alfa(string $path): string
    {
        return $this->tenantUrl('alfa', $path);
    }

    private function order(): string
    {
        return $this->withToken($this->admin)->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->plan])->assertCreated()->json('data.id');
    }

    /**
     * Archivo real: el servidor mira el contenido, no el nombre.
     */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mpPayment(string $order, array $overrides = []): array
    {
        return $overrides + [
            'id' => 900100,
            'status' => 'approved',
            'transaction_amount' => 99,
            'currency_id' => 'PEN',
            'external_reference' => $order,
            'payment_method_id' => 'visa',
            'payment_type_id' => 'credit_card',
        ];
    }

    private function connectPlatformAccount(): void
    {
        $this->mercadoPago(['/users/me' => Http::response(['id' => 1, 'nickname' => 'INTELECTALAB', 'site_id' => 'MPE'])]);
        $response = $this->withToken($this->platform())->putJson($this->centralUrl('/platform/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave-de-la-plataforma'])
            ->assertOk()
            ->assertJsonPath('data.0.connected', true)
            ->assertJsonPath('data.0.account.name', 'INTELECTALAB');

        // La clave no vuelve por la API ni queda en claro en la base
        $this->assertStringNotContainsString('APP_USR', $response->getContent());
        $this->assertStringNotContainsString('APP_USR', (string) DB::table('platform_settings')->where('key', 'payment_gateway_MERCADOPAGO')->value('value'));
    }

    public function test_an_organization_attaches_its_payment_proof_and_the_platform_sees_it(): void
    {
        $order = $this->order();
        Mail::assertSent(PlanOrderMail::class, fn (PlanOrderMail $mail) => $mail->hasTo('root@plataforma.test') && str_contains($mail->title, 'Nueva orden de plan'));

        // Un archivo que no es lo que su nombre dice no se acepta
        $this->withToken($this->admin)->post($this->alfa("/subscription/orders/{$order}/proof"), ['file' => $this->upload('constancia.png', '<?php echo 1;')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->withToken($this->admin)->post($this->alfa("/subscription/orders/{$order}/proof"), ['file' => $this->upload('constancia.png', $this->png())], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.proof.name', 'constancia.png');
        Mail::assertSent(PlanOrderMail::class, fn (PlanOrderMail $mail) => $mail->hasTo('root@plataforma.test') && str_contains($mail->title, 'Pago informado'));

        $this->withToken($this->admin)->getJson($this->alfa('/subscription/plans'))->assertJsonPath('data.pending_order.proof.name', 'constancia.png');
        $this->withToken($this->admin)->get($this->alfa("/subscription/orders/{$order}/proof"))->assertOk()->assertHeader('Content-Type', 'image/png');

        // La plataforma la ve en su lista, la descarga y sabe que hay un pago por revisar
        $platform = $this->platform();
        $this->withToken($platform)->getJson($this->centralUrl('/platform/plan-orders'))->assertJsonPath('data.0.proof.name', 'constancia.png');
        $this->withToken($platform)->get($this->centralUrl("/platform/plan-orders/{$order}/proof"))->assertOk();
        $this->withToken($platform)->getJson($this->centralUrl('/platform/plan-orders/summary'))
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.informed', 1);

        // Otra academia no llega a la constancia
        $this->withToken($this->login('beta', 'admin@beta.com'))->get($this->tenantUrl('beta', "/subscription/orders/{$order}/proof"))->assertNotFound();

        // Se puede quitar mientras la orden siga pendiente
        $this->withToken($this->admin)->deleteJson($this->alfa("/subscription/orders/{$order}/proof"))->assertOk()->assertJsonPath('data.proof', null);
    }

    public function test_the_organization_is_told_how_its_order_was_resolved_until_it_acknowledges_it(): void
    {
        $order = $this->order();
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))->assertJsonPath('meta.order_notice', null);

        $this->withToken($this->platform())->postJson($this->centralUrl("/platform/plan-orders/{$order}/confirm"), ['method' => 'TRANSFER'])->assertOk();

        Mail::assertSent(PlanOrderMail::class, fn (PlanOrderMail $mail) => $mail->hasTo('admin@alfa.com') && str_contains($mail->title, 'Tu pago fue confirmado'));
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('meta.order_notice.status', 'PAID')
            ->assertJsonPath('meta.order_notice.plan_name', 'Hasta 50 alumnos');

        $this->withToken($this->admin)->postJson($this->alfa('/subscription/notice/seen'))->assertOk();
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))->assertJsonPath('meta.order_notice', null);

        // Si la plataforma cancela, se avisa con el motivo; si cancela la academia, no hay nada que avisarle
        $second = $this->order();
        $this->withToken($this->platform())->postJson($this->centralUrl("/platform/plan-orders/{$second}/cancel"), ['reason' => 'Pediste el plan equivocado.'])->assertOk();
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))
            ->assertJsonPath('meta.order_notice.status', 'CANCELLED')
            ->assertJsonPath('meta.order_notice.notes', 'Pediste el plan equivocado.');
        $this->withToken($this->admin)->postJson($this->alfa('/subscription/notice/seen'))->assertOk();

        $third = $this->order();
        $this->withToken($this->admin)->postJson($this->alfa("/subscription/orders/{$third}/cancel"))->assertOk();
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))->assertJsonPath('meta.order_notice', null);
    }

    public function test_an_unpaid_order_expires_on_its_own_unless_a_payment_was_reported(): void
    {
        $order = $this->order();
        $this->withToken($this->admin)->getJson($this->alfa('/subscription/plans'))
            ->assertJsonPath('data.pending_order.id', $order)
            ->assertJsonPath('data.pending_order.expires_at', now()->addDays(7)->toIso8601String());

        $this->travel(8)->days();
        $this->artisan('plan-orders:expire')->assertSuccessful();

        $admin = $this->login('alfa', 'admin@alfa.com');
        $this->withToken($admin)->getJson($this->alfa('/subscription/plans'))
            ->assertJsonPath('data.pending_order', null)
            ->assertJsonPath('data.orders.0.status', 'EXPIRED');
        $this->withToken($admin)->getJson($this->alfa('/subscription'))->assertJsonPath('meta.order_notice.status', 'EXPIRED');
        Mail::assertSent(PlanOrderMail::class, fn (PlanOrderMail $mail) => $mail->hasTo('admin@alfa.com') && str_contains($mail->title, 'venció'));

        // Vencida la anterior, se puede pedir otra; con un pago informado ya no vence sola
        $second = $this->withToken($admin)->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->plan])->assertCreated()->json('data.id');
        $this->withToken($admin)->putJson($this->alfa("/subscription/orders/{$second}/payment"), ['payer_reference' => 'Transferencia BCP, operación 123456'])->assertOk();

        $this->travel(5)->days();
        $this->artisan('plan-orders:expire')->assertSuccessful();
        $this->withToken($this->platform())->getJson($this->centralUrl('/platform/plan-orders?status=PENDING'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('meta.counts.EXPIRED', 1);
    }

    public function test_the_platform_undoes_a_confirmation_made_by_mistake(): void
    {
        $order = $this->order();
        $platform = $this->platform();
        $revert = $this->centralUrl("/platform/plan-orders/{$order}/revert");

        // Solo se deshace una orden pagada, y con motivo
        $this->withToken($platform)->postJson($revert, ['reason' => 'Era el pago de otra academia.'])->assertStatus(409)->assertJsonPath('code', 'PLAN_ORDER_NOT_REVERTIBLE');
        $this->withToken($platform)->postJson($this->centralUrl("/platform/plan-orders/{$order}/confirm"), ['method' => 'TRANSFER'])->assertOk();
        $this->withToken($platform)->postJson($revert, [])->assertStatus(422);

        $this->withToken($platform)->postJson($revert, ['reason' => 'Era el pago de otra academia.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.paid_at', null)
            ->assertJsonPath('data.notes', 'Confirmación anulada: Era el pago de otra academia.');

        // El plan vuelve a como estaba (en prueba) y la academia lo sabe
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))
            ->assertJsonPath('data.state', 'TRIAL')
            ->assertJsonPath('data.max_students', 10)
            ->assertJsonPath('meta.order_notice', null);
        Mail::assertSent(PlanOrderMail::class, fn (PlanOrderMail $mail) => $mail->hasTo('admin@alfa.com') && str_contains($mail->title, 'Se anuló la confirmación'));

        // La orden reabierta se puede confirmar de nuevo
        $this->withToken($platform)->postJson($this->centralUrl("/platform/plan-orders/{$order}/confirm"), ['method' => 'YAPE'])->assertOk();

        // Si después se cambió el plan desde la ficha, deshacer pisaría ese cambio: no se permite
        $this->withToken($platform)->putJson($this->centralUrl("/platform/tenants/{$this->alfa->id}/subscription"), ['plan_id' => $this->plan, 'ends_at' => now()->addDays(90)->toDateString()])->assertOk();
        $this->withToken($platform)->postJson($revert, ['reason' => 'Otro error de confirmación.'])
            ->assertStatus(409)->assertJsonPath('code', 'PLAN_ORDER_NOT_REVERTIBLE');
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))->assertJsonPath('data.state', 'ACTIVE');
    }

    public function test_an_organization_pays_its_plan_online_with_the_platform_account(): void
    {
        $order = $this->order();

        // Sin cuenta conectada no se ofrece el pago en línea
        $this->withToken($this->admin)->getJson($this->alfa('/subscription/plans'))->assertJsonCount(0, 'data.online_gateways');
        $this->connectPlatformAccount();
        $this->withToken($this->admin)->getJson($this->alfa('/subscription/plans'))->assertJsonPath('data.online_gateways.0.code', 'MERCADOPAGO');

        $pay = fn (string $returnUrl) => $this->withToken($this->admin)->postJson($this->alfa("/subscription/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => $returnUrl]);
        $pay('https://sitio-ajeno.com/x')->assertStatus(422);

        $this->mercadoPago(['/checkout/preferences' => Http::response(['id' => 'PREF-9', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-9'], 201)]);
        $pay('https://alfa.localhost/settings/plan')->assertCreated()->assertJsonPath('data.checkout_url', 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-9');

        // Se cobra con la cuenta de la plataforma y el aviso va al dominio central
        Http::assertSent(fn (GatewayRequest $request) => $request->hasHeader('Authorization', 'Bearer APP_USR-clave-de-la-plataforma')
            && $request['external_reference'] === $order
            && $request['items'][0]['unit_price'] == 99
            && $request['notification_url'] === 'https://localhost/api/v1/platform/payments/webhooks/mercadopago');

        // Volver sin haber pagado no cambia nada
        $this->mercadoPago(['/v1/payments/search*' => Http::response(['results' => []])]);
        $this->withToken($this->admin)->postJson($this->alfa("/subscription/orders/{$order}/pay/sync"))->assertOk()->assertJsonPath('data.status', 'PENDING');

        // Llega el aviso a la plataforma, sin sesión: el pago se consulta en Mercado Pago
        $this->mercadoPago(['/v1/payments/900100' => Http::response($this->mpPayment($order))]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $notify = fn () => $this->postJson($this->centralUrl('/platform/payments/webhooks/mercadopago?data.id=900100&type=payment'));
        $notify()->assertOk();
        $notify()->assertOk();

        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.plan.name', 'Hasta 50 alumnos')
            ->assertJsonPath('meta.order_notice.status', 'PAID');

        $platform = $this->platform();
        $this->withToken($platform)->getJson($this->centralUrl('/platform/plan-orders?status=PAID'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.paid_online', true)
            ->assertJsonPath('data.0.payment_method', 'CARD')
            ->assertJsonPath('data.0.payment_reference', '900100')
            ->assertJsonPath('data.0.resolved_by', 'Mercado Pago');

        // Lo que cobró la pasarela no se deshace desde la consola: sería una devolución
        $this->withToken($platform)->postJson($this->centralUrl("/platform/plan-orders/{$order}/revert"), ['reason' => 'Prueba de anulación.'])
            ->assertStatus(409)->assertJsonPath('code', 'PLAN_ORDER_NOT_REVERTIBLE');
    }

    public function test_an_online_charge_that_does_not_match_the_plan_order_is_left_for_review(): void
    {
        $order = $this->order();
        $this->connectPlatformAccount();
        $this->mercadoPago(['/checkout/preferences' => Http::response(['id' => 'PREF-9', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-9'], 201)]);
        $this->withToken($this->admin)->postJson($this->alfa("/subscription/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => 'https://alfa.localhost/settings/plan'])->assertCreated();

        // Mercado Pago informa un importe menor al de la orden
        $this->mercadoPago(['/v1/payments/search*' => Http::response(['results' => [$this->mpPayment($order, ['transaction_amount' => 9])]])]);
        $this->withToken($this->admin)->postJson($this->alfa("/subscription/orders/{$order}/pay/sync"))->assertOk()->assertJsonPath('data.status', 'PENDING');

        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))->assertJsonPath('data.state', 'TRIAL');
        $platform = $this->platform();
        $this->withToken($platform)->getJson($this->centralUrl('/platform/plan-orders/summary'))->assertJsonPath('data.review', 1);
        $this->assertStringContainsString('PEN 9.00', (string) $this->withToken($platform)->getJson($this->centralUrl('/platform/plan-orders'))->json('data.0.review_reason'));
        $this->assertSame(1, PlanOrder::query()->whereNotNull('review_reason')->count());
        Mail::assertSent(PlanOrderMail::class, fn (PlanOrderMail $mail) => $mail->hasTo('root@plataforma.test') && str_contains($mail->title, 'Cobro por revisar'));

        // Un administrador de academia no llega a las cuentas de cobro de la plataforma
        $this->withToken($this->admin)->getJson($this->centralUrl('/platform/payment-gateways'))->assertUnauthorized();
    }
}
