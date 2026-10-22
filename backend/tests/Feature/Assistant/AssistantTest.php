<?php

namespace Tests\Feature\Assistant;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asistente, entrega 1: cifras de ventas, lo más vendido, matrículas y
 * pendientes de un periodo, y el catálogo que cada rol puede buscar. Solo
 * lectura, acotado a la academia y a los permisos de cada persona.
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.trial_days' => 365]);
        // 2 de marzo de 2026, 10:00 en Lima
        $this->travelTo('2026-03-02 15:00:00');
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function course(string $title, ?int $price): string
    {
        $admin = fn () => $this->withToken($this->admin);
        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'language' => 'es', 'price' => $price])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    private function buy(string $email, string $course, bool $pay = true): string
    {
        $this->flushHeaders();
        $order = $this->withToken($this->login('acme', $email))->postJson($this->api('/orders'), ['course_id' => $course])->assertCreated()->json('data.id');
        $this->flushHeaders();

        if ($pay) {
            $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE'])->assertOk();
        }

        return $order;
    }

    private function student(string $email): User
    {
        return $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => $email]);
    }

    public function test_sales_and_top_sellers_of_the_period_leave_out_refunds_and_cancellations(): void
    {
        $excel = $this->course('Excel para finanzas', 100);
        $powerBi = $this->course('Power BI', 250);
        foreach (['ana', 'luis', 'rosa', 'eva'] as $name) {
            $this->student("{$name}@acme.com");
        }

        $this->buy('ana@acme.com', $excel);
        $this->buy('luis@acme.com', $excel);
        $this->buy('luis@acme.com', $powerBi);
        // Rosa compró y le devolvieron el dinero; la orden de Eva quedó sin pagar y se canceló
        $refunded = $this->buy('rosa@acme.com', $powerBi);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$refunded}/refund"), ['reason' => 'Se inscribió por error.'])->assertOk();
        $pending = $this->buy('eva@acme.com', $powerBi, pay: false);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$pending}/cancel"))->assertOk();

        $this->flushHeaders()->withToken($this->admin)->getJson($this->api('/assistant/insights?from=2026-03-02&to=2026-03-02'))
            ->assertOk()
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.sales.revenue', '450.00')
            ->assertJsonPath('data.sales.orders', 3)
            ->assertJsonPath('data.sales.top.0.title', 'Power BI')
            ->assertJsonPath('data.sales.top.0.units', 1)
            ->assertJsonPath('data.sales.top.1.title', 'Excel para finanzas')
            ->assertJsonPath('data.sales.top.1.units', 2)
            // Las matrículas nuevas del día cuentan todas, aunque después se cancelaran
            ->assertJsonPath('data.enrollments.scope', 'all')
            ->assertJsonPath('data.plan.max_students', 10);

        // Otro día no hay ventas
        $this->withToken($this->admin)->getJson($this->api('/assistant/insights?from=2026-03-01&to=2026-03-01'))
            ->assertJsonPath('data.sales.revenue', '0.00')
            ->assertJsonPath('data.sales.top', []);
    }

    public function test_pending_items_include_expiring_and_inactive_enrollments(): void
    {
        $excel = $this->course('Excel para finanzas', null);
        $ana = $this->student('ana@acme.com');
        $luis = $this->student('luis@acme.com');
        $admin = fn () => $this->withToken($this->admin);

        $admin()->postJson($this->api("/courses/{$excel}/enrollments"), ['student_ids' => [$ana->id, $luis->id]])->assertCreated();
        // A Ana se le vence en 5 días
        $enrollment = collect($admin()->getJson($this->api("/courses/{$excel}/enrollments"))->json('data'))->firstWhere('student.email', 'ana@acme.com')['id'];
        $admin()->putJson($this->api("/enrollments/{$enrollment}"), ['starts_at' => '2026-03-02', 'ends_at' => '2026-03-07'])->assertOk();

        // Tres semanas después nadie avanzó: los dos están inactivos y a Ana ya se le venció
        $this->travelTo('2026-03-05 15:00:00');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($this->admin)->getJson($this->api('/assistant/insights?from=2026-03-05&to=2026-03-05'))
            ->assertJsonPath('data.pending.enrollments_expiring', 1)
            ->assertJsonPath('data.pending.inactive_students', 0);

        $this->travelTo('2026-03-23 15:00:00');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($this->admin)->getJson($this->api('/assistant/insights?from=2026-03-23&to=2026-03-23'))
            ->assertJsonPath('data.pending.enrollments_expiring', 0)
            ->assertJsonPath('data.pending.inactive_students', 1);
    }

    public function test_each_role_only_sees_its_own_sections_and_catalog(): void
    {
        $excel = $this->course('Excel para finanzas', 100);
        $word = $this->course('Word básico', null);
        $rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'rosa@acme.com']);
        $this->withToken($this->admin)->putJson($this->api("/courses/{$word}/teachers"), ['user_ids' => [$rosa->id]])->assertOk();
        $this->student('ana@acme.com');
        $this->buy('ana@acme.com', $excel);

        // El docente: sin ventas ni plan, matrículas solo de sus cursos, y en el catálogo solo los suyos
        $teacher = $this->flushHeaders()->login('acme', 'rosa@acme.com');
        $this->withToken($teacher)->getJson($this->api('/assistant/insights?from=2026-03-02&to=2026-03-02'))
            ->assertOk()
            ->assertJsonPath('data.sales', null)
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.enrollments.scope', 'assigned')
            ->assertJsonPath('data.enrollments.new', 0)
            ->assertJsonPath('data.pending.payment_reviews', null);
        $this->withToken($teacher)->getJson($this->api('/assistant/catalog'))
            ->assertJsonPath('data.staff', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Word básico');

        // La alumna: nada del negocio, y en el catálogo solo lo que está a la venta
        $student = $this->flushHeaders()->login('acme', 'ana@acme.com');
        $this->withToken($student)->getJson($this->api('/assistant/insights'))
            ->assertJsonPath('data.sales', null)
            ->assertJsonPath('data.enrollments', null)
            ->assertJsonPath('data.plan', null);
        $this->withToken($student)->getJson($this->api('/assistant/catalog'))
            ->assertJsonPath('data.staff', false)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.price', '100.00');

        // Otra academia no ve nada de esta
        $other = $this->provisionTenant('beta', 'admin@beta.com');
        $this->assertNotSame($other->id, $this->tenant->id);
        $this->flushHeaders()->withToken($this->login('beta', 'admin@beta.com'))->getJson($this->tenantUrl('beta', '/assistant/catalog'))
            ->assertJsonCount(0, 'data.items');
    }
}
