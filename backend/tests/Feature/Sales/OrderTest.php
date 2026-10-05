<?php

namespace Tests\Feature\Sales;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S5: venta a personas. Catálogo → checkout → orden → pago confirmado → matrícula.
 */
class OrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * Curso publicado con una lección.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function publishedCourse(array $attributes = []): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), $attributes + ['title' => 'Excel para finanzas', 'language' => 'es'])
            ->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto reservado.'])
            ->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    private function student(string $email = 'ana@acme.com'): User
    {
        return $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => $email]);
    }

    private function placeOrder(string $token, string $course, array $extra = []): string
    {
        return $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $course] + $extra)
            ->assertCreated()->json('data.id');
    }

    public function test_public_catalog_lists_only_published_courses_with_a_price(): void
    {
        $paid = $this->publishedCourse(['price' => 120.5]);
        $free = $this->publishedCourse(['title' => 'Curso gratuito', 'price' => 0]);
        $unpriced = $this->publishedCourse(['title' => 'Solo matrícula manual']);
        $draft = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'Borrador', 'language' => 'es', 'price' => 50])->json('data.id');

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->getJson($this->api('/catalog/courses'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.currency', 'PEN')
            ->assertJsonPath('data.0.title', 'Curso gratuito')
            ->assertJsonPath('data.0.price', '0.00')
            ->assertJsonPath('data.1.id', $paid)
            ->assertJsonPath('data.1.price', '120.50');

        // La ficha muestra el temario, no el contenido
        $detail = $this->getJson($this->api("/catalog/courses/{$paid}"))
            ->assertOk()
            ->assertJsonPath('data.sections.0.items.0.title', 'Bienvenida');
        $this->assertStringNotContainsString('Texto reservado', $detail->getContent());

        $this->getJson($this->api("/catalog/courses/{$unpriced}"))->assertNotFound();
        $this->getJson($this->api("/catalog/courses/{$draft}"))->assertNotFound();
        $this->getJson($this->api("/catalog/courses/{$free}"))->assertOk();
    }

    public function test_price_is_saved_without_creating_pending_changes(): void
    {
        $course = $this->publishedCourse(['price' => 100]);

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 80])
            ->assertOk()
            ->assertJsonPath('data.price', '80.00')
            ->assertJsonPath('data.pending_changes', false);

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => -1])
            ->assertStatus(422);
        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 10.999])
            ->assertStatus(422);

        $event = AuditEvent::query()->where('action', 'courses.updated')->latest('created_at')->firstOrFail();
        $this->assertSame(['price' => '100.00'], $event->before);
        $this->assertSame(['price' => '80.00'], $event->after);
    }

    public function test_purchase_creates_a_pending_order_and_confirmed_payment_enrolls_the_buyer(): void
    {
        $this->travelTo('2026-03-10 09:00:00');

        $course = $this->publishedCourse(['price' => 120, 'access_days' => 30]);
        $student = $this->student();
        $token = $this->login('acme', $student->email);

        $order = $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $course, 'expected_total' => 120])
            ->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.total_amount', '120.00')
            ->assertJsonPath('data.items.0.title', 'Excel para finanzas')
            ->assertJsonPath('data.items.0.enrolled', false)
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.payment_instructions.type', 'MANUAL')
            ->json('data');

        $this->assertSame('2026-03-13', substr($order['expires_at'], 0, 10));

        // Sin pago no hay acceso
        $this->withToken($token)->getJson($this->api("/me/courses/{$course}"))->assertNotFound();

        $this->withToken($this->admin)->postJson($this->api("/orders/{$order['id']}/confirm-payment"), [
            'method' => 'YAPE', 'reference' => 'OP-778899', 'notes' => 'Captura por WhatsApp',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.items.0.enrolled', true)
            ->assertJsonPath('data.payment.method', 'YAPE')
            ->assertJsonPath('data.payment.reference', 'OP-778899')
            ->assertJsonPath('data.payment.amount', '120.00')
            ->assertJsonPath('data.payment.confirmed_by', 'Ada Admin');

        $this->withToken($token)->getJson($this->api("/me/courses/{$course}"))->assertOk();
        $this->withToken($token)->getJson($this->api("/me/orders/{$order['id']}"))
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payment_instructions', null);

        app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($student) {
            $enrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();
            $this->assertSame(Enrollment::SOURCE_PURCHASE, $enrollment->source);
            // La vigencia sale de los días de acceso del curso
            $this->assertSame('2026-04-09', $enrollment->ends_at->toDateString());

            foreach (['orders.created', 'payments.confirmed', 'orders.paid', 'enrollments.created'] as $action) {
                $this->assertTrue(AuditEvent::query()->where('action', $action)->exists(), $action);
            }
        });
    }

    public function test_a_payment_is_applied_only_once(): void
    {
        $course = $this->publishedCourse(['price' => 120]);
        $student = $this->student();
        $order = $this->placeOrder($this->login('acme', $student->email), $course);

        $confirm = fn () => $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER']);

        $confirm()->assertOk();
        $confirm()->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PENDING');

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(1, Enrollment::query()->count());
        });
    }

    public function test_checkout_uses_the_server_price_and_rejects_a_stale_total(): void
    {
        $course = $this->publishedCourse(['price' => 120]);
        $token = $this->login('acme', $this->student()->email);

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 150])->assertOk();

        $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $course, 'expected_total' => 120])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRICE_CHANGED')
            ->assertJsonPath('details.total', '150.00');

        $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $course, 'expected_total' => 150])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '150.00');

        // El precio de la orden queda congelado aunque el curso cambie después (RN-019)
        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 999])->assertOk();
        $this->withToken($token)->getJson($this->api('/me/orders'))->assertJsonPath('data.0.total_amount', '150.00');
    }

    public function test_a_course_cannot_be_bought_twice(): void
    {
        $course = $this->publishedCourse(['price' => 120]);
        $unpriced = $this->publishedCourse(['title' => 'Solo matrícula manual']);
        $student = $this->student();
        $token = $this->login('acme', $student->email);

        $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $unpriced])
            ->assertStatus(409)->assertJsonPath('code', 'COURSE_NOT_FOR_SALE');

        $order = $this->placeOrder($token, $course);

        $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $course])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_ALREADY_PENDING')
            ->assertJsonPath('details.order_id', $order);

        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'CASH'])->assertOk();

        $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $course])
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_ENROLLED');
    }

    public function test_a_free_course_is_paid_and_enrolled_at_once(): void
    {
        $course = $this->publishedCourse(['price' => 0]);
        $token = $this->login('acme', $this->student()->email);

        $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $course, 'expected_total' => 0])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.total_amount', '0.00')
            ->assertJsonPath('data.items.0.enrolled', true)
            ->assertJsonPath('data.payment', null);

        $this->withToken($token)->getJson($this->api("/me/courses/{$course}"))->assertOk();
    }

    public function test_pending_orders_can_be_cancelled_and_paid_ones_cannot(): void
    {
        $course = $this->publishedCourse(['price' => 120]);
        $other = $this->publishedCourse(['title' => 'Power BI', 'price' => 90]);
        $token = $this->login('acme', $this->student()->email);

        $first = $this->placeOrder($token, $course);

        $this->withToken($token)->postJson($this->api("/me/orders/{$first}/cancel"))
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->withToken($token)->postJson($this->api("/me/orders/{$first}/cancel"))
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PENDING');
        $this->withToken($this->admin)->postJson($this->api("/orders/{$first}/confirm-payment"), ['method' => 'CASH'])
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PENDING');

        // Cancelada la anterior, puede volver a comprar
        $second = $this->placeOrder($token, $course);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$second}/cancel"))
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $paid = $this->placeOrder($token, $other);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$paid}/confirm-payment"), ['method' => 'CASH'])->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/orders/{$paid}/cancel"))
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PENDING');
    }

    public function test_pending_orders_expire_after_the_configured_hours(): void
    {
        $this->travelTo('2026-03-10 09:00:00');

        $course = $this->publishedCourse(['price' => 120]);
        $token = $this->login('acme', $this->student()->email);
        $order = $this->placeOrder($token, $course);

        $this->travelTo('2026-03-13 09:00:01');
        $token = $this->login('acme', 'ana@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        // Vencida aunque la tarea programada aún no haya corrido
        $this->withToken($token)->getJson($this->api("/me/orders/{$order}"))
            ->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('data.payment_instructions', null);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'CASH'])
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PENDING');
        $this->withToken($this->admin)->getJson($this->api('/orders?status=EXPIRED'))->assertJsonCount(1, 'data');
        $this->withToken($this->admin)->getJson($this->api('/orders?status=PENDING'))->assertJsonCount(0, 'data');

        $this->artisan('orders:expire')->assertSuccessful();

        app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($order) {
            $this->assertSame(Order::STATUS_EXPIRED, Order::query()->findOrFail($order)->status);
            $this->assertTrue(AuditEvent::query()->where('action', 'orders.expired')->exists());
        });

        // Vencida la anterior, puede generar una nueva
        $this->placeOrder($token, $course);
    }

    public function test_orders_are_listed_for_staff_and_scoped_for_buyers(): void
    {
        $course = $this->publishedCourse(['price' => 120]);
        $ana = $this->student();
        $luis = $this->student('luis@acme.com');
        $support = $this->createUser($this->tenant, RoleCatalog::SUPPORT);
        $sales = $this->createUser($this->tenant, RoleCatalog::SALES);
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER);

        $anaToken = $this->login('acme', $ana->email);
        $anaOrder = $this->placeOrder($anaToken, $course);
        $luisToken = $this->login('acme', $luis->email);
        $luisOrder = $this->placeOrder($luisToken, $course);

        // Cada comprador ve solo lo suyo
        $this->withToken($anaToken)->getJson($this->api('/me/orders'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $anaOrder);
        $this->withToken($anaToken)->getJson($this->api("/me/orders/{$luisOrder}"))->assertNotFound();
        $this->withToken($anaToken)->postJson($this->api("/me/orders/{$luisOrder}/cancel"))->assertNotFound();
        $this->withToken($anaToken)->getJson($this->api('/orders'))->assertForbidden();
        $this->withToken($anaToken)->postJson($this->api("/orders/{$anaOrder}/confirm-payment"), ['method' => 'CASH'])->assertForbidden();

        $this->withToken($this->admin)->getJson($this->api('/orders'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.number', 2)
            ->assertJsonPath('data.0.customer.email', 'luis@acme.com');
        $this->withToken($this->admin)->getJson($this->api('/orders?search=ana@'))->assertJsonCount(1, 'data');
        $this->withToken($this->admin)->getJson($this->api('/orders?search=2'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $luisOrder);

        // Soporte y Ventas consultan; solo quien tiene el permiso confirma pagos o cancela
        foreach ([$support, $sales] as $staff) {
            $token = $this->login('acme', $staff->email);
            $this->withToken($token)->getJson($this->api('/orders'))->assertOk();
            $this->withToken($token)->getJson($this->api("/orders/{$anaOrder}"))->assertOk();
            $this->withToken($token)->postJson($this->api("/orders/{$anaOrder}/confirm-payment"), ['method' => 'CASH'])->assertForbidden();
            $this->withToken($token)->postJson($this->api("/orders/{$anaOrder}/cancel"))->assertForbidden();
        }

        $teacherToken = $this->login('acme', $teacher->email);
        $this->withToken($teacherToken)->getJson($this->api('/orders'))->assertForbidden();
        $this->withToken($teacherToken)->postJson($this->api('/orders'), ['course_id' => $course])->assertForbidden();
    }

    public function test_orders_are_isolated_between_organizations(): void
    {
        $course = $this->publishedCourse(['price' => 120]);
        $order = $this->placeOrder($this->login('acme', $this->student()->email), $course);

        $this->provisionTenant('beta', 'admin@beta.com');
        $beta = $this->login('beta', 'admin@beta.com');

        $this->withToken($beta)->getJson($this->tenantUrl('beta', '/orders'))->assertJsonCount(0, 'data');
        $this->withToken($beta)->getJson($this->tenantUrl('beta', "/orders/{$order}"))->assertNotFound();
        $this->withToken($beta)->postJson($this->tenantUrl('beta', "/orders/{$order}/confirm-payment"), ['method' => 'CASH'])->assertNotFound();
        $this->withToken($beta)->postJson($this->tenantUrl('beta', '/orders'), ['course_id' => $course])->assertStatus(422);
        $this->getJson($this->tenantUrl('beta', "/catalog/courses/{$course}"))->assertNotFound();
    }

    public function test_a_visitor_registers_as_a_student_and_can_buy(): void
    {
        $course = $this->publishedCourse(['price' => 0]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $payload = [
            'first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => ' Rosa@Correo.com ',
            'password' => 'clave-segura-1', 'password_confirmation' => 'clave-segura-1',
        ];

        $token = $this->postJson($this->api('/auth/register'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'rosa@correo.com')
            ->assertJsonPath('data.user.roles', ['Alumno'])
            ->json('data.token');

        $this->withToken($token)->postJson($this->api('/orders'), ['course_id' => $course])->assertCreated();
        $this->withToken($token)->getJson($this->api('/users'))->assertForbidden();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->postJson($this->api('/auth/register'), $payload)
            ->assertStatus(422)->assertJsonStructure(['details' => ['email']]);
        $this->postJson($this->api('/auth/register'), ['email' => 'otra@correo.com', 'password' => 'corta', 'password_confirmation' => 'distinta'] + $payload)
            ->assertStatus(422)->assertJsonStructure(['details' => ['password']]);

        // El mismo correo puede registrarse en otra organización
        $this->provisionTenant('beta', 'admin@beta.com');
        $this->postJson($this->tenantUrl('beta', '/auth/register'), $payload)->assertCreated();

        $this->assertTrue(app(TenantContext::class)->runForTenant(
            $this->tenant->id,
            fn () => AuditEvent::query()->where('action', 'users.registered')->exists()
        ));
    }
}
