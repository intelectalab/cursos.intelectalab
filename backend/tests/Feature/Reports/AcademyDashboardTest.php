<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel de la academia: académico, ventas, personas y atención en un
 * periodo; cada pestaña solo para quien puede ver su módulo; la descarga en
 * Excel; y los pendientes de Inicio.
 */
class AcademyDashboardTest extends TestCase
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
     * @return array{course: string, resources: list<string>}
     */
    private function publishedCourse(string $title, int $price): array
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'price' => $price, 'language' => 'es', 'issues_certificate' => true])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');

        $resources = [];
        foreach (['Bienvenida', 'Cierre'] as $resource) {
            $resources[] = $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => $resource, 'body' => 'Texto.'])->json('data.id');
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

    /**
     * @return array<string, mixed>
     */
    private function section(string $section, string $token, string $query = 'from=2026-03-08&to=2026-03-14'): array
    {
        return $this->withToken($token)->getJson($this->api("/dashboard/{$section}?{$query}"))->assertOk()->json('data');
    }

    /**
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function card(array $panel, string $label): array
    {
        return collect($panel['cards'])->firstWhere('label', $label) ?? $this->fail("No está la tarjeta «{$label}»");
    }

    /**
     * @param  array<string, mixed>  $panel
     * @return array<string, float|int>
     */
    private function chart(array $panel, string $title): array
    {
        $chart = collect($panel['charts'])->firstWhere('title', $title) ?? $this->fail("No está el gráfico «{$title}»");

        return collect($chart['items'])->pluck('value', 'label')->all();
    }

    /**
     * Usuario con un rol propio de la academia y su token.
     *
     * @param  list<string>  $permissions
     */
    private function tokenWith(array $permissions): string
    {
        $roleId = $this->withToken($this->admin)->postJson($this->api('/roles'), ['name' => 'Rol '.implode(' ', $permissions), 'permissions' => $permissions])
            ->assertCreated()->json('data.id');

        $user = app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($roleId) {
            $user = User::factory()->create(['password' => self::PASSWORD]);
            $user->roles()->attach($roleId);

            return $user;
        });

        return $this->login('acme', $user->email);
    }

    public function test_the_panel_summarizes_the_period_by_section(): void
    {
        ['course' => $excel, 'resources' => $excelResources] = $this->publishedCourse('Excel', 100);
        ['course' => $powerBi] = $this->publishedCourse('Power BI', 250);
        [$ana, $luis, $rosa] = [$this->student('ana@acme.com'), $this->student('luis@acme.com'), $this->student('rosa@acme.com')];

        // 2 de marzo (periodo anterior): Ana compra Excel
        $this->buy($ana, $excel);

        // 10 de marzo: Luis compra los dos; Rosa deja una orden sin pagar y recibe una matrícula manual
        $this->at('2026-03-10 15:00:00');
        $this->buy($luis, $excel);
        $this->buy($luis, $powerBi);
        $this->buy($rosa, $powerBi, pay: false);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$excel}/enrollments"), ['student_ids' => [$rosa->id]])->assertCreated();

        // 12 de marzo: Ana completa Excel (y recibe su certificado), Luis avanza a medias y Rosa abre una consulta
        $this->at('2026-03-12 15:00:00');
        $anaToken = $this->login('acme', $ana->email);
        foreach ($excelResources as $resource) {
            $this->withToken($anaToken)->postJson($this->api("/me/courses/{$excel}/resources/{$resource}/complete"))->assertOk();
        }
        $this->withToken($this->login('acme', $luis->email))->postJson($this->api("/me/courses/{$excel}/resources/{$excelResources[0]}/complete"))->assertOk();
        $this->withToken($this->login('acme', $rosa->email))->postJson($this->api('/tickets'), ['subject' => 'No veo mi curso', 'category' => 'ACCESS', 'body' => 'Ayuda, por favor.'])->assertCreated();

        $this->at('2026-03-12 18:00:00');

        $academic = $this->section('academic', $this->admin);
        $this->assertSame(2, $this->card($academic, 'Cursos publicados')['value']);
        $this->assertSame([3, 1], [$this->card($academic, 'Matrículas nuevas')['value'], $this->card($academic, 'Matrículas nuevas')['previous']]);
        $this->assertSame(1, $this->card($academic, 'Cursos completados')['value']);
        $this->assertSame(1, $this->card($academic, 'Certificados emitidos')['value']);
        $this->assertSame(['En curso' => 3, 'Completadas' => 1], $this->chart($academic, 'Matrículas por situación'));
        $this->assertSame(['title' => 'Excel', 'enrolled' => 3, 'completed' => 1, 'rate' => 33, 'to_grade' => 0], $academic['table']['rows'][0]);

        $sales = $this->section('sales', $this->admin);
        $this->assertSame([350, 100], [$this->card($sales, 'Cobrado en el periodo')['value'], $this->card($sales, 'Cobrado en el periodo')['previous']]);
        $this->assertSame('PEN', $this->card($sales, 'Cobrado en el periodo')['currency']);
        $this->assertSame([2, 1], [$this->card($sales, 'Ventas')['value'], $this->card($sales, 'Ventas')['previous']]);
        $this->assertSame(1, $this->card($sales, 'Órdenes por pagar')['value']);
        $this->assertSame(['Yape' => 350], $this->chart($sales, 'Cobrado por medio de pago en el periodo'));
        $this->assertSame(['Power BI' => 250, 'Excel' => 100], $this->chart($sales, 'Cursos más vendidos en el periodo'));
        $this->assertSame(450, array_sum($this->chart($sales, 'Cobrado por mes')));
        $this->assertCount(3, $sales['table']['rows']);

        $people = $this->section('people', $this->admin);
        $this->assertSame(3, $this->card($people, 'Alumnos con acceso')['value']);
        // Las tres cuentas se crearon el 2 de marzo, en el periodo anterior
        $this->assertSame([0, 3], [$this->card($people, 'Alumnos nuevos')['value'], $this->card($people, 'Alumnos nuevos')['previous']]);
        $this->assertSame(2, $this->card($people, 'Alumnos activos')['value']);
        $this->assertSame([], $people['table']['rows']);

        $support = $this->section('support', $this->admin);
        $this->assertSame(1, $this->card($support, 'Consultas abiertas')['value']);
        $this->assertSame(1, $this->card($support, 'Esperan respuesta')['value']);
        $this->assertSame(['Acceso' => 1], $this->chart($support, 'Consultas del periodo por tema'));
        $this->assertSame('No veo mi curso', $support['table']['rows'][0]['subject']);

        // Un periodo anterior a todo: las cifras del periodo en cero, la situación de hoy igual
        $empty = $this->section('sales', $this->admin, 'from=2026-01-01&to=2026-01-31');
        $this->assertSame(0, $this->card($empty, 'Cobrado en el periodo')['value']);
        $this->assertSame(1, $this->card($empty, 'Órdenes por pagar')['value']);

        $this->withToken($this->admin)->getJson($this->api('/dashboard/sales?from=2026-03-14&to=2026-03-08'))->assertUnprocessable();
        $this->withToken($this->admin)->getJson($this->api('/dashboard/unknown'))->assertNotFound();

        $excelFile = $this->withToken($this->admin)->get($this->api('/dashboard/export?from=2026-03-08&to=2026-03-14'));
        $excelFile->assertOk();
        $this->assertStringContainsString('spreadsheetml', $excelFile->headers->get('content-type'));
    }

    public function test_each_section_needs_the_permission_of_its_module(): void
    {
        // Reportes y órdenes: solo la pestaña de ventas
        $sales = $this->tokenWith(['reports.read', 'orders.read']);
        $this->section('sales', $sales);
        $this->withToken($sales)->getJson($this->api('/dashboard/academic'))->assertForbidden();
        $this->withToken($sales)->getJson($this->api('/dashboard/support'))->assertForbidden();
        // Descargar el Excel pide además exportar reportes
        $this->withToken($sales)->get($this->api('/dashboard/export'))->assertForbidden();

        // Sin reportes no hay panel, aunque vea el módulo
        $this->withToken($this->tokenWith(['orders.read']))->getJson($this->api('/dashboard/sales'))->assertForbidden();

        // Un docente no ve el panel
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'docente@acme.com']);
        $this->withToken($this->login('acme', $teacher->email))->getJson($this->api('/dashboard/academic'))->assertForbidden();
    }

    public function test_home_pending_counts_reach_only_who_can_attend_them(): void
    {
        ['course' => $excel] = $this->publishedCourse('Excel', 0);
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'docente@acme.com']);
        $student = $this->student('ana@acme.com');

        $this->withToken($this->admin)->getJson($this->api('/alerts/summary'))->assertOk()
            ->assertJsonPath('data.submissions_to_grade', 0)
            ->assertJsonPath('data.overdue_installments', 0)
            ->assertJsonPath('data.claims_unanswered', 0);

        // El docente solo ve lo de sus cursos, y nada de cobros ni reclamos
        $this->withToken($this->admin)->postJson($this->api("/courses/{$excel}/participants"), ['role' => 'TEACHER', 'user_ids' => [$teacher->id]])->assertSuccessful();
        $this->withToken($this->login('acme', $teacher->email))->getJson($this->api('/alerts/summary'))->assertOk()
            ->assertJsonPath('data.submissions_to_grade', 0)
            ->assertJsonPath('data.overdue_installments', null)
            ->assertJsonPath('data.claims_unanswered', null)
            ->assertJsonPath('data.payment_reviews', null);

        $this->withToken($this->login('acme', $student->email))->getJson($this->api('/alerts/summary'))->assertForbidden();
    }
}
