<?php

namespace Tests\Feature\Sales;

use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Venta asistida: el personal registra una compra a nombre de un alumno,
 * ya registrado o nuevo, con cupón o descuento manual, y dice cómo paga.
 */
class AssistedSaleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $course;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $admin = fn () => $this->withToken($this->as('admin@acme.com'));

        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 200])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::SALES, ['first_name' => 'Vera', 'last_name' => 'Ventas', 'email' => 'vera@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::SUPPORT, ['email' => 'sol@acme.com']);
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function as(string $email): string
    {
        $this->flushHeaders();

        return $this->login('acme', $email);
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    private function sell(array $sale, string $as = 'vera@acme.com'): TestResponse
    {
        return $this->withToken($this->as($as))->postJson($this->api('/orders/assisted'), $sale + ['course_id' => $this->course]);
    }

    private function enrolled(User $student): bool
    {
        return app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Enrollment::query()
            ->where('student_id', $student->id)->where('course_id', $this->course)->where('status', Enrollment::STATUS_ACTIVE)->exists());
    }

    private function mailTo(string $email, string $title): bool
    {
        return Mail::sent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo($email) && str_starts_with($mail->title, $title))->isNotEmpty();
    }

    public function test_a_sale_paid_at_the_office_enrolls_the_student_at_once(): void
    {
        Mail::fake();

        $this->sell(['student_id' => $this->ana->id, 'mode' => 'PAID', 'payment' => ['method' => 'CASH', 'reference' => 'Recibo 0042']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.sold_by', 'Vera Ventas')
            ->assertJsonPath('data.payment.method', 'CASH')
            ->assertJsonPath('data.payment.reference', 'Recibo 0042')
            ->assertJsonPath('data.payment.confirmed_by', 'Vera Ventas')
            ->assertJsonPath('data.total_amount', '200.00');

        $this->assertTrue($this->enrolled($this->ana));
        $this->assertTrue($this->mailTo('ana@acme.com', 'Pago confirmado'));
        $this->assertFalse($this->mailTo('ana@acme.com', 'Recibimos tu orden'));

        // Tarjeta en el POS de la academia también se registra
        $luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com']);
        $this->sell(['student_id' => $luis->id, 'mode' => 'PAID', 'payment' => ['method' => 'CARD']])->assertCreated()->assertJsonPath('data.payment.method', 'CARD');

        // Sin decir cómo pagó no se registra; ni dos veces el mismo curso
        $this->sell(['student_id' => $this->createUser($this->tenant, RoleCatalog::STUDENT)->id, 'mode' => 'PAID'])->assertStatus(422)->assertJsonValidationErrors(['payment'], 'details');
        $this->sell(['student_id' => $this->ana->id, 'mode' => 'PAID', 'payment' => ['method' => 'CASH']])
            ->assertStatus(409)
            ->assertJsonPath('message', 'El alumno ya tiene una matrícula en este curso.');
    }

    public function test_pay_later_with_a_deadline_and_a_manual_discount(): void
    {
        Mail::fake();
        $deadline = now()->addDays(5)->startOfMinute();

        $order = $this->sell([
            'student_id' => $this->ana->id,
            'mode' => 'LATER',
            'expires_at' => $deadline->toIso8601String(),
            'discount' => ['amount' => 50, 'reason' => 'Convenio con la municipalidad'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.discount_amount', '50.00')
            ->assertJsonPath('data.total_amount', '150.00')
            ->assertJsonPath('data.discount_reason', 'Convenio con la municipalidad')
            ->json('data');

        $this->assertTrue($deadline->equalTo($order['expires_at']));
        $this->assertFalse($this->enrolled($this->ana));
        $this->assertTrue($this->mailTo('ana@acme.com', 'Recibimos tu orden'));

        // El alumno la ve en «Mis compras» y puede pagarla como cualquier otra
        $this->withToken($this->as('ana@acme.com'))->getJson($this->api("/me/orders/{$order['id']}"))->assertOk()->assertJsonPath('data.total_amount', '150.00');

        // Reglas del descuento: con motivo, no mayor que el precio y sin cupón a la vez
        $luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com'])->id;
        $this->sell(['student_id' => $luis, 'mode' => 'LATER', 'discount' => ['amount' => 10]])->assertStatus(422)->assertJsonValidationErrors(['discount.reason'], 'details');
        $this->sell(['student_id' => $luis, 'mode' => 'LATER', 'discount' => ['amount' => 250, 'reason' => 'Error']])->assertStatus(422);
        $this->sell(['student_id' => $luis, 'mode' => 'LATER', 'discount' => ['amount' => 10, 'reason' => 'Beca'], 'coupon_code' => 'HOLA'])->assertStatus(422);
        $this->sell(['student_id' => $luis, 'mode' => 'LATER', 'expires_at' => now()->addDays(120)->toIso8601String()])->assertStatus(422)->assertJsonValidationErrors(['expires_at'], 'details');
    }

    public function test_a_new_student_is_created_and_invited_in_the_same_step(): void
    {
        Mail::fake();

        $order = $this->sell([
            'student' => ['first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'Rosa@Correo.com', 'doc_type' => 'DNI', 'doc_number' => '45678912'],
            'mode' => 'PAID',
            'payment' => ['method' => 'YAPE'],
        ])->assertCreated()->assertJsonPath('data.customer.email', 'rosa@correo.com')->json('data');

        $rosa = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => User::query()->where('email', 'rosa@correo.com')->firstOrFail());
        $this->assertTrue($rosa->hasPermission('enrollments.self.read'));
        $this->assertNotNull($rosa->invited_at);
        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo('rosa@correo.com'));
        $this->assertSame($rosa->id, $order['customer']['id']);

        // El correo ya existe: se le pide buscarla entre los registrados
        $this->sell(['student' => ['first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@acme.com'], 'mode' => 'LATER'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student.email'], 'details');
    }

    public function test_installments_with_or_without_the_first_payment(): void
    {
        Mail::fake();
        $plan = [['amount' => 100, 'due_date' => now()->toDateString()], ['amount' => 100, 'due_date' => now()->addMonth()->toDateString()]];

        // Pagó la primera: entra al curso y debe el resto
        $this->sell(['student_id' => $this->ana->id, 'mode' => 'INSTALLMENTS', 'installments' => $plan, 'payment' => ['method' => 'TRANSFER']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PARTIAL');
        $this->assertTrue($this->enrolled($this->ana));

        // Sin pago: espera la primera cuota y recibe la orden
        $luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com']);
        $this->sell(['student_id' => $luis->id, 'mode' => 'INSTALLMENTS', 'installments' => $plan])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonCount(2, 'data.installments');
        $this->assertTrue($this->mailTo('luis@acme.com', 'Recibimos tu orden'));

        // Las cuotas deben sumar el total
        $eva = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'eva@acme.com']);
        $this->sell(['student_id' => $eva->id, 'mode' => 'INSTALLMENTS', 'installments' => [['amount' => 50, 'due_date' => now()->toDateString()], ['amount' => 50, 'due_date' => now()->addMonth()->toDateString()]]])
            ->assertStatus(422);
        $this->assertSame(0, app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Order::query()->where('customer_id', $eva->id)->count()));
    }

    public function test_who_can_sell_options_search_and_the_assisted_filter(): void
    {
        // Soporte no vende; Ventas y el Administrador sí
        $this->sell(['student_id' => $this->ana->id, 'mode' => 'LATER'], 'sol@acme.com')->assertForbidden();

        $vera = $this->as('vera@acme.com');
        $this->withToken($vera)->getJson($this->api('/orders/assisted/options'))
            ->assertOk()
            ->assertJsonPath('data.courses.0.title', 'Excel para finanzas')
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonFragment(['payment_methods' => ['TRANSFER', 'YAPE', 'CASH', 'OTHER', 'CARD']]);
        $this->withToken($vera)->getJson($this->api('/orders/assisted/students?search=torr'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'ana@acme.com');

        // Una compra del propio alumno y una asistida: el filtro las separa
        $this->withToken($this->as('ana@acme.com'))->postJson($this->api('/orders'), ['course_id' => $this->course])->assertCreated();
        $luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com']);
        $this->sell(['student_id' => $luis->id, 'mode' => 'LATER'], 'admin@acme.com')->assertCreated();

        $admin = $this->as('admin@acme.com');
        $this->withToken($admin)->getJson($this->api('/orders?sale=ASSISTED'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer.email', 'luis@acme.com');
        $this->withToken($admin)->getJson($this->api('/orders?sale=ONLINE'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer.email', 'ana@acme.com');
        $this->withToken($admin)->get($this->api('/orders/export?sale=ASSISTED'))->assertOk();
    }
}
