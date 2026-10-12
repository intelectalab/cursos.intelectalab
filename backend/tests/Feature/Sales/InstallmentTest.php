<?php

namespace Tests\Feature\Sales;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Sales\Domain\Models\PaymentAttempt;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RN-012, FP-15: una orden se paga en cuotas. El personal arma el plan, el
 * comprador entra al curso con la primera cuota y lo que no se paga a tiempo
 * queda como deuda vencida.
 */
class InstallmentTest extends TestCase
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
        // Las cuotas se siguen durante meses: la academia de prueba no debe caer en solo lectura por su prueba gratuita
        config(['billing.trial_days' => 365]);
        // Mediodía en Lima
        $this->travelTo('2026-03-02 17:00:00');

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $admin = fn () => $this->withToken($this->admin);
        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 300])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com', 'first_name' => 'Ana', 'last_name' => 'Torres']);
        $this->buyer = $this->login('acme', 'ana@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function order(): string
    {
        return $this->withToken($this->buyer)->postJson($this->api('/orders'), ['course_id' => $this->course])->assertCreated()->json('data.id');
    }

    /**
     * @param  list<array{0: float|int, 1: string}>  $rows  importe y fecha de cada cuota
     */
    private function plan(string $order, array $rows): TestResponse
    {
        return $this->withToken($this->admin)->putJson($this->api("/orders/{$order}/installments"), [
            'installments' => array_map(fn (array $row) => ['amount' => $row[0], 'due_date' => $row[1]], $rows),
        ]);
    }

    private function confirm(string $order): TestResponse
    {
        return $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER', 'reference' => 'OP-'.uniqid()]);
    }

    /**
     * Mueve el reloj y renueva las sesiones, que vencen por inactividad.
     */
    private function at(string $moment): void
    {
        $this->travelTo($moment);
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->buyer = $this->login('acme', 'ana@acme.com');
    }

    /**
     * @param  array<string, mixed>  $responses  por ruta de la API de Mercado Pago
     */
    private function mercadoPago(array $responses): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(collect($responses)->mapWithKeys(fn ($response, string $path) => [self::MP.$path => $response])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function mpPayment(string $order, int $id, float $amount): array
    {
        return [
            'id' => $id, 'status' => 'approved', 'transaction_amount' => $amount, 'currency_id' => 'PEN',
            'external_reference' => $order, 'payment_type_id' => 'credit_card', 'date_approved' => '2026-03-02T13:00:00.000-04:00',
        ];
    }

    public function test_the_staff_sets_a_payment_plan_and_the_buyer_gets_in_with_the_first_installment(): void
    {
        $order = $this->order();

        // El plan debe sumar el total, tener al menos dos cuotas y fechas que avanzan desde hoy
        $this->plan($order, [[300, '2026-03-05']])->assertStatus(422);
        $this->plan($order, [[100, '2026-03-05'], [100, '2026-04-05']])->assertStatus(422)->assertJsonStructure(['details' => ['installments']]);
        $this->plan($order, [[150, '2026-03-01'], [150, '2026-04-05']])->assertStatus(422);
        $this->plan($order, [[150, '2026-04-05'], [150, '2026-03-05']])->assertStatus(422);

        $this->plan($order, [[100, '2026-03-05'], [100, '2026-04-05'], [100, '2026-05-05']])->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonCount(3, 'data.installments')
            ->assertJsonPath('data.installments.0.amount', '100.00')
            ->assertJsonPath('data.debt.pending_amount', '300.00')
            ->assertJsonPath('data.debt.next.number', 1)
            // La orden espera su primera cuota hasta el final de ese día en Lima
            ->assertJsonPath('data.expires_at', '2026-03-06T04:59:59+00:00');

        // Se puede rehacer mientras nadie haya pagado
        $this->plan($order, [[200, '2026-03-05'], [100, '2026-04-05']])->assertOk()->assertJsonCount(2, 'data.installments');
        $this->plan($order, [[100, '2026-03-05'], [100, '2026-04-05'], [100, '2026-05-05']])->assertOk();

        // El comprador ve su plan y todavía no tiene el curso
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertOk()
            ->assertJsonPath('data.installments.1.due_date', '2026-04-05')
            ->assertJsonPath('data.debt.next.amount', '100.00');
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertStatus(404);

        // Primera cuota: entra al curso y la orden queda en cuotas
        $this->confirm($order)->assertOk()
            ->assertJsonPath('data.status', 'PARTIAL')
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.installments.0.status', 'PAID')
            ->assertJsonPath('data.installments.0.payment.method', 'TRANSFER')
            ->assertJsonPath('data.installments.0.payment.confirmed_by', 'Ada Admin')
            ->assertJsonPath('data.items.0.enrolled', true)
            ->assertJsonPath('data.debt.paid_amount', '100.00')
            ->assertJsonPath('data.debt.pending_amount', '200.00')
            ->assertJsonPath('data.debt.next.number', 2)
            ->assertJsonPath('data.expires_at', null);
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertOk();

        // Con un pago recibido, el plan ya no cambia y la orden no se cancela
        $this->plan($order, [[100, '2026-04-05'], [100, '2026-05-05']])->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PENDING');
        $this->withToken($this->admin)->deleteJson($this->api("/orders/{$order}/installments"))->assertStatus(409);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/cancel"))->assertStatus(409);

        // Segunda cuota: sigue en cuotas. Tercera: pagada.
        $this->confirm($order)->assertOk()->assertJsonPath('data.status', 'PARTIAL')->assertJsonPath('data.debt.pending_amount', '100.00');
        $this->confirm($order)->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.debt.pending_amount', '0.00')
            ->assertJsonPath('data.debt.next', null);
        $this->confirm($order)->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PENDING');

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(3, Payment::query()->whereNotNull('installment_id')->count());
            $this->assertSame('300.00', number_format((float) Payment::query()->sum('amount'), 2, '.', ''));
            $this->assertSame(1, AuditEvent::query()->where('action', 'orders.partially_paid')->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'orders.paid')->count());
        });
    }

    public function test_an_order_whose_first_installment_is_not_paid_in_time_expires_and_a_plan_can_be_removed(): void
    {
        $order = $this->order();
        $this->plan($order, [[150, '2026-03-05'], [150, '2026-04-05']])->assertOk();

        // Sin plan, la orden vuelve a pagarse de una vez
        $this->withToken($this->admin)->deleteJson($this->api("/orders/{$order}/installments"))->assertOk()
            ->assertJsonPath('data.installments', [])
            ->assertJsonPath('data.debt', null);
        $this->plan($order, [[150, '2026-03-05'], [150, '2026-04-05']])->assertOk();

        // Pasado el día de la primera cuota sin pago, la orden vence: no hay deuda porque nunca entró
        $this->at('2026-03-07 17:00:00');
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))->assertJsonPath('data.status', 'EXPIRED');
        $this->confirm($order)->assertStatus(409);
        $this->withToken($this->admin)->getJson($this->api('/orders/installments'))->assertJsonPath('meta.counts.ALL', 0);

        // Las ventas a empresas y las inscripciones gratuitas no llevan cuotas; un alumno no arma planes
        $this->withToken($this->buyer)->putJson($this->api("/orders/{$order}/installments"), ['installments' => []])->assertForbidden();
        $this->withToken($this->buyer)->getJson($this->api('/orders/installments'))->assertForbidden();
        $this->withToken($this->buyer)->getJson($this->api('/orders/installments/alerts'))->assertForbidden();
    }

    public function test_each_installment_is_charged_online_for_its_own_amount(): void
    {
        $this->mercadoPago(['/users/me' => Http::response(['id' => 99, 'nickname' => 'ACADEMIAACME', 'site_id' => 'MPE'])]);
        $this->withToken($this->admin)->putJson($this->api('/settings/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave-de-la-academia'])->assertOk();

        $order = $this->order();
        $this->plan($order, [[200, '2026-03-05'], [100, '2026-04-05']])->assertOk();

        $pay = fn () => $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => "https://acme.localhost/my-orders/{$order}"]);
        $sync = fn () => $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay/sync"));

        // Primera cuota: a Mercado Pago se le pide cobrar S/ 200, no el total
        $this->mercadoPago(['/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-1'], 201)]);
        $pay()->assertCreated();
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::MP.'/checkout/preferences'
            && $request['items'][0]['unit_price'] === 200.0
            && str_contains($request['items'][0]['title'], 'cuota 1 de 2'));

        $this->mercadoPago(['/v1/payments/search*' => Http::response(['results' => [$this->mpPayment($order, 555001, 200)]])]);
        $sync()->assertOk()
            ->assertJsonPath('data.status', 'PARTIAL')
            ->assertJsonPath('data.installments.0.payment.reference', '555001')
            ->assertJsonPath('data.installments.0.payment.gateway', 'MERCADOPAGO')
            // Sigue pudiendo pagar en línea lo que falta
            ->assertJsonPath('data.online_gateways.0.code', 'MERCADOPAGO');

        // Volver a consultar no cobra otra vez la misma cuota
        $sync()->assertOk()->assertJsonPath('data.status', 'PARTIAL')->assertJsonPath('data.debt.pending_amount', '100.00');

        // Segunda cuota: S/ 100. Mercado Pago devuelve los dos pagos de la orden; solo el nuevo se aplica
        $this->mercadoPago(['/checkout/preferences' => Http::response(['id' => 'PREF-2', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-2'], 201)]);
        $pay()->assertCreated();
        Http::assertSent(fn (GatewayRequest $request) => $request->url() === self::MP.'/checkout/preferences' && $request['items'][0]['unit_price'] === 100.0);

        $this->mercadoPago(['/v1/payments/search*' => Http::response(['results' => [$this->mpPayment($order, 555001, 200), $this->mpPayment($order, 555002, 100)]])]);
        $sync()->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.installments.1.payment.reference', '555002')
            ->assertJsonPath('data.online_gateways', []);

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(2, Payment::query()->count());
            $this->assertSame(0, PaymentAttempt::query()->where('status', PaymentAttempt::STATUS_NEEDS_REVIEW)->count());
        });
    }

    public function test_an_order_in_installments_is_refunded_for_what_was_collected_and_reports_count_each_payment(): void
    {
        $this->mercadoPago(['/users/me' => Http::response(['id' => 99, 'nickname' => 'ACADEMIAACME', 'site_id' => 'MPE'])]);
        $this->withToken($this->admin)->putJson($this->api('/settings/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave-de-la-academia'])->assertOk();

        $order = $this->order();
        $this->plan($order, [[100, '2026-03-05'], [100, '2026-04-05'], [100, '2026-05-05']])->assertOk();

        // Sin ningún pago no hay qué devolver
        $refund = fn (array $extra = []) => $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'El alumno se retiró del curso.'] + $extra);
        $refund()->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_REFUNDABLE');

        // Cuota 1 por fuera; cuota 2 en línea
        $this->confirm($order)->assertOk();
        $this->mercadoPago(['/checkout/preferences' => Http::response(['id' => 'PREF-2', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-2'], 201)]);
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => "https://acme.localhost/my-orders/{$order}"])->assertCreated();
        $paid = ['/v1/payments/search*' => Http::response(['results' => [$this->mpPayment($order, 555002, 100)]])];
        $this->mercadoPago($paid);
        $this->withToken($this->buyer)->postJson($this->api("/me/orders/{$order}/pay/sync"))->assertOk()->assertJsonPath('data.status', 'PARTIAL');

        // Reportes: lo cobrado cuenta cuota a cuota aunque la orden no esté pagada, y se ve lo que falta cobrar
        $report = fn () => $this->withToken($this->admin)->getJson($this->api('/reports/overview?from=2026-03-01&to=2026-03-31'));
        $report()->assertOk()
            ->assertJsonPath('data.totals.revenue', '200.00')
            ->assertJsonPath('data.totals.installment_revenue', '200.00')
            ->assertJsonPath('data.totals.paid_orders', 0)
            ->assertJsonPath('data.series.1.revenue', '200.00')
            // La venta en cuotas ya cuenta entre los cursos más vendidos, por su importe completo
            ->assertJsonPath('data.top_courses.0.title', 'Excel para finanzas')
            ->assertJsonPath('data.top_courses.0.units', 1)
            ->assertJsonPath('data.top_courses.0.revenue', '300.00')
            ->assertJsonPath('data.today.receivable_installments', 1)
            ->assertJsonPath('data.today.receivable_amount', '100.00')
            ->assertJsonPath('data.today.overdue_amount', '0.00');

        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))
            ->assertJsonPath('data.collected.amount', '200.00')
            ->assertJsonPath('data.collected.online_amount', '100.00')
            ->assertJsonPath('data.collected.manual_amount', '100.00')
            ->assertJsonPath('data.collected.gateways', ['MERCADOPAGO']);

        // Si Mercado Pago no devuelve, no se toca nada
        $this->mercadoPago($paid + ['/v1/payments/555002/refunds' => Http::response(['message' => 'The payment is too old to be refunded'], 400)]);
        $refund()->assertStatus(502)->assertJsonPath('code', 'GATEWAY_ERROR');
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))->assertJsonPath('data.status', 'PARTIAL')->assertJsonPath('data.collected.amount', '200.00');

        // Devuelve por la pasarela lo cobrado en línea y registra lo devuelto por fuera
        $this->mercadoPago($paid + ['/v1/payments/555002/refunds' => Http::response(['id' => 880033, 'status' => 'approved'], 201)]);
        $refund(['reference' => 'Transferencia BCP, operación 4455'])->assertOk()
            ->assertJsonPath('data.status', 'REFUNDED')
            ->assertJsonPath('data.installments.0.payment.refund.reference', 'Transferencia BCP, operación 4455')
            ->assertJsonPath('data.installments.1.payment.refund.reference', '880033')
            ->assertJsonPath('data.refunded_amount', '200.00')
            ->assertJsonPath('data.collected.amount', '0.00');
        Http::assertSentCount(2);

        // El alumno pierde el acceso, la cuota que faltaba ya no se debe y lo devuelto deja de ser ingreso
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertNotFound();
        $this->withToken($this->admin)->getJson($this->api('/orders/installments'))->assertJsonPath('meta.counts.ALL', 0);
        $report()->assertJsonPath('data.totals.revenue', '0.00')->assertJsonPath('data.today.receivable_amount', '0.00');
        $refund()->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_REFUNDABLE');

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(2, Payment::query()->where('status', Payment::STATUS_REFUNDED)->count());
            $this->assertSame('200.00', AuditEvent::query()->where('action', 'orders.refunded')->sole()->after['refunded']);
        });
    }

    public function test_an_unpaid_installment_becomes_an_overdue_debt_the_staff_is_alerted_about(): void
    {
        $order = $this->order();
        $this->plan($order, [[100, '2026-03-05'], [100, '2026-04-05'], [100, '2026-05-05']])->assertOk();
        $this->confirm($order)->assertOk();

        $due = fn (string $query = '') => $this->withToken($this->admin)->getJson($this->api('/orders/installments'.$query));
        $alerts = fn () => $this->withToken($this->admin)->getJson($this->api('/orders/installments/alerts'));

        // Al día: dos cuotas por cobrar, ninguna vencida
        $due()->assertOk()
            ->assertJsonPath('meta.counts', ['ALL' => 2, 'OVERDUE' => 0, 'UPCOMING' => 2])
            ->assertJsonPath('meta.amounts.pending', '200.00')
            ->assertJsonPath('data.0.order.customer.name', 'Ana Torres');
        $alerts()->assertOk()->assertJsonCount(0, 'data');

        // El día del vencimiento todavía no es deuda; al día siguiente sí
        $this->at('2026-04-05 17:00:00');
        $alerts()->assertJsonCount(0, 'data');

        $this->at('2026-04-08 17:00:00');
        $alerts()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 2)
            ->assertJsonPath('data.0.days_overdue', 3)
            ->assertJsonPath('data.0.order.title', 'Excel para finanzas')
            ->assertJsonPath('data.0.order.customer.email', 'ana@acme.com');
        $due('?filter=OVERDUE')->assertJsonCount(1, 'data')->assertJsonPath('meta.amounts.overdue', '100.00');
        $due('?filter=UPCOMING')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 3);

        // La orden y el comprador lo ven; el acceso no se quita solo
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))
            ->assertJsonPath('data.debt.overdue_count', 1)
            ->assertJsonPath('data.debt.overdue_amount', '100.00')
            ->assertJsonPath('data.installments.1.overdue', true);
        $this->withToken($this->buyer)->getJson($this->api("/me/orders/{$order}"))->assertJsonPath('data.debt.next.overdue', true);
        $this->withToken($this->buyer)->getJson($this->api("/me/courses/{$this->course}"))->assertOk();

        // Pagada la cuota, deja de ser deuda
        $this->confirm($order)->assertOk()->assertJsonPath('data.debt.overdue_count', 0);
        $alerts()->assertJsonCount(0, 'data');
    }
}
