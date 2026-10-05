<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S7: indicadores base. Cada cifra sale de lo que realmente pasó en el periodo.
 */
class OverviewReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-02 15:00:00');
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * Mueve el reloj y renueva la sesión del administrador, que vence por inactividad.
     */
    private function at(string $moment): void
    {
        $this->travelTo($moment);
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{course: string, resources: list<string>}
     */
    private function publishedCourse(array $attributes): array
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), $attributes + ['language' => 'es', 'issues_certificate' => true])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');

        $resources = [];
        foreach (['Bienvenida', 'Cierre'] as $title) {
            $resources[] = $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => $title, 'body' => 'Texto.'])->json('data.id');
        }

        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return compact('course', 'resources');
    }

    private function student(string $email): User
    {
        return $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => $email]);
    }

    private function buy(User $student, string $course, bool $pay = true): void
    {
        $order = $this->withToken($this->login('acme', $student->email))->postJson($this->api('/orders'), ['course_id' => $course])
            ->assertCreated()->json('data.id');

        if ($pay) {
            $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE'])->assertOk();
        }
    }

    public function test_overview_reports_what_happened_in_the_period(): void
    {
        ['course' => $excel, 'resources' => $excelResources] = $this->publishedCourse(['title' => 'Excel', 'price' => 100]);
        ['course' => $powerBi] = $this->publishedCourse(['title' => 'Power BI', 'price' => 250]);
        [$ana, $luis, $rosa] = [$this->student('ana@acme.com'), $this->student('luis@acme.com'), $this->student('rosa@acme.com')];

        // 2 de marzo: Ana compra Excel
        $this->buy($ana, $excel);

        // 10 de marzo: Luis compra los dos; Rosa deja una orden sin pagar y recibe una matrícula manual
        $this->at('2026-03-10 15:00:00');
        $this->buy($luis, $excel);
        $this->buy($luis, $powerBi);
        $this->buy($rosa, $powerBi, pay: false);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$excel}/enrollments"), ['student_ids' => [$rosa->id]])->assertCreated();

        // 12 de marzo: Ana completa Excel (y recibe su certificado); Luis avanza a medias
        $this->at('2026-03-12 15:00:00');
        $anaToken = $this->login('acme', $ana->email);
        foreach ($excelResources as $resource) {
            $this->withToken($anaToken)->postJson($this->api("/me/courses/{$excel}/resources/{$resource}/complete"))->assertOk();
        }
        $this->withToken($this->login('acme', $luis->email))->postJson($this->api("/me/courses/{$excel}/resources/{$excelResources[0]}/complete"))->assertOk();

        // Se consulta el mismo 12, cuando la orden de Rosa aún está dentro de su plazo de pago
        $this->at('2026-03-12 18:00:00');

        $this->withToken($this->admin)->getJson($this->api('/reports/overview?from=2026-03-08&to=2026-03-14'))
            ->assertOk()
            ->assertJsonPath('data.period.days', 7)
            ->assertJsonPath('data.period.previous_from', '2026-03-01')
            ->assertJsonPath('data.period.previous_to', '2026-03-07')
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.totals.paid_orders', 2)
            ->assertJsonPath('data.totals.revenue', '350.00')
            ->assertJsonPath('data.totals.new_enrollments', 3)
            ->assertJsonPath('data.totals.purchased_enrollments', 2)
            ->assertJsonPath('data.totals.active_students', 2)
            ->assertJsonPath('data.totals.completed_enrollments', 1)
            ->assertJsonPath('data.totals.certificates_issued', 1)
            // Periodo anterior: solo la compra de Ana
            ->assertJsonPath('data.previous.paid_orders', 1)
            ->assertJsonPath('data.previous.revenue', '100.00')
            ->assertJsonPath('data.previous.new_enrollments', 1)
            ->assertJsonPath('data.previous.active_students', 0)
            // Situación de hoy
            ->assertJsonPath('data.today.pending_orders', 1)
            ->assertJsonPath('data.today.pending_amount', '250.00')
            ->assertJsonPath('data.today.students_with_access', 3)
            ->assertJsonPath('data.today.published_courses', 2)
            ->assertJsonPath('data.today.valid_certificates', 1)
            // Serie diaria: un punto por día, con ceros
            ->assertJsonCount(7, 'data.series')
            ->assertJsonPath('data.series.0.date', '2026-03-08')
            ->assertJsonPath('data.series.0.revenue', '0.00')
            ->assertJsonPath('data.series.2.date', '2026-03-10')
            ->assertJsonPath('data.series.2.revenue', '350.00')
            ->assertJsonPath('data.series.2.paid_orders', 2)
            ->assertJsonPath('data.series.2.new_enrollments', 3)
            // Más vendidos, por ingreso
            ->assertJsonCount(2, 'data.top_courses')
            ->assertJsonPath('data.top_courses.0.title', 'Power BI')
            ->assertJsonPath('data.top_courses.0.revenue', '250.00')
            ->assertJsonPath('data.top_courses.1.units', 1)
            // Finalización de siempre: Excel 1 de 3, Power BI 0 de 1
            ->assertJsonPath('data.completion.0.title', 'Excel')
            ->assertJsonPath('data.completion.0.enrolled', 3)
            ->assertJsonPath('data.completion.0.completed', 1)
            ->assertJsonPath('data.completion.0.rate', 33)
            ->assertJsonPath('data.completion.1.rate', 0);
    }

    public function test_days_follow_the_organization_timezone(): void
    {
        ['course' => $course] = $this->publishedCourse(['title' => 'Excel', 'price' => 100]);

        // 03:00 UTC del 11 de marzo son las 22:00 del 10 en Lima
        $this->at('2026-03-11 03:00:00');
        $this->buy($this->student('ana@acme.com'), $course);

        $this->withToken($this->admin)->getJson($this->api('/reports/overview?from=2026-03-10&to=2026-03-10'))
            ->assertJsonPath('data.period.timezone', 'America/Lima')
            ->assertJsonPath('data.totals.paid_orders', 1)
            ->assertJsonPath('data.series.0.date', '2026-03-10')
            ->assertJsonPath('data.series.0.paid_orders', 1);

        $this->withToken($this->admin)->getJson($this->api('/reports/overview?from=2026-03-11&to=2026-03-11'))
            ->assertJsonPath('data.totals.paid_orders', 0);

        // Sin fechas: los últimos 30 días hasta hoy, en la hora de la organización
        $this->withToken($this->admin)->getJson($this->api('/reports/overview'))
            ->assertJsonPath('data.period.to', '2026-03-10')
            ->assertJsonPath('data.period.from', '2026-02-09')
            ->assertJsonPath('data.period.days', 30);
    }

    public function test_the_period_is_validated(): void
    {
        $get = fn (string $query) => $this->withToken($this->admin)->getJson($this->api("/reports/overview?{$query}"));

        $get('from=2026-03-10&to=2026-03-01')->assertStatus(422);
        $get('from=2026-03-10')->assertStatus(422);
        $get('from=10/03/2026&to=2026-03-12')->assertStatus(422);
        $get('from=2025-01-01&to=2026-03-01')->assertStatus(422)->assertJsonStructure(['details' => ['days']]);
        $get('from=2025-03-02&to=2026-03-02')->assertOk()->assertJsonPath('data.period.days', 366);
    }

    public function test_reports_are_isolated_and_require_permission(): void
    {
        ['course' => $course] = $this->publishedCourse(['title' => 'Excel', 'price' => 100]);
        $ana = $this->student('ana@acme.com');
        $this->buy($ana, $course);

        foreach ([RoleCatalog::STUDENT, RoleCatalog::TEACHER, RoleCatalog::SUPPORT, RoleCatalog::SALES] as $role) {
            $token = $this->login('acme', $this->createUser($this->tenant, $role)->email);
            $this->withToken($token)->getJson($this->api('/reports/overview'))->assertForbidden();
        }

        $this->provisionTenant('beta', 'admin@beta.com');
        $this->withToken($this->login('beta', 'admin@beta.com'))->getJson($this->tenantUrl('beta', '/reports/overview'))
            ->assertOk()
            ->assertJsonPath('data.totals.paid_orders', 0)
            ->assertJsonPath('data.totals.revenue', '0.00')
            ->assertJsonPath('data.today.students_with_access', 0)
            ->assertJsonCount(0, 'data.completion');
    }
}
