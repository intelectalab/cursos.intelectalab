<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de alumnos y ficha "Alumno 360".
 */
class StudentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $course;

    private User $ana;

    private User $beto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $admin = fn () => $this->withToken($this->admin);

        // Curso gratuito publicado con una lección
        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 0])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, [
            'first_name' => 'Ana', 'last_name' => 'Alumna', 'email' => 'ana@acme.com', 'doc_type' => 'DNI', 'doc_number' => '45678912',
        ]);
        $this->beto = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Beto', 'last_name' => 'Becerra', 'email' => 'beto@acme.com']);

        $admin()->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$this->ana->id]])->assertCreated();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    public function test_students_are_listed_with_counts_search_and_filters(): void
    {
        // El administrador no es alumno: no aparece
        $this->withToken($this->admin)->getJson($this->api('/students'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.counts.ALL', 2)
            ->assertJsonPath('meta.counts.ENROLLED', 1)
            ->assertJsonPath('meta.counts.NOT_ENROLLED', 1)
            ->assertJsonPath('meta.counts.INACTIVE', 0)
            ->assertJsonPath('data.0.name', 'Ana Alumna')
            ->assertJsonPath('data.0.document', 'DNI 45678912')
            ->assertJsonPath('data.0.active_enrollments', 1)
            ->assertJsonPath('data.0.completed_courses', 0)
            ->assertJsonPath('data.1.active_enrollments', 0);

        $this->withToken($this->admin)->getJson($this->api('/students?filter=NOT_ENROLLED'))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Beto Becerra');
        // Se busca también por documento
        $this->withToken($this->admin)->getJson($this->api('/students?search=4567'))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'ana@acme.com');
        $this->withToken($this->admin)->getJson($this->api('/students?filter=OTRO'))->assertStatus(422);
    }

    public function test_student_profile_gathers_enrollments_orders_certificates_and_tickets(): void
    {
        $ana = $this->login('acme', 'ana@acme.com');

        // Ana completa su curso, compra otro gratuito y abre un ticket
        $resource = $this->withToken($ana)->getJson($this->api("/me/courses/{$this->course}"))->json('data.sections.0.resources.0.id');
        $this->withToken($ana)->postJson($this->api("/me/courses/{$this->course}/resources/{$resource}/complete"))->assertOk();

        $other = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'Power BI', 'language' => 'es', 'price' => 0])->json('data.id');
        $section = $this->withToken($this->admin)->postJson($this->api("/courses/{$other}/sections"), ['title' => 'Inicio'])->json('data.id');
        $this->withToken($this->admin)->postJson($this->api("/courses/{$other}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Hola', 'body' => 'Hola.'])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$other}/publish"))->assertOk();
        $this->withToken($ana)->postJson($this->api('/orders'), ['course_id' => $other], ['Idempotency-Key' => 'ficha-360'])->assertCreated();

        $this->withToken($ana)->postJson($this->api('/tickets'), ['subject' => 'No veo mi curso', 'category' => 'ACCESS', 'body' => 'Ayuda, por favor.'])->assertCreated();

        $this->withToken($this->admin)->getJson($this->api("/students/{$this->ana->id}"))
            ->assertOk()
            ->assertJsonPath('data.student.name', 'Ana Alumna')
            ->assertJsonPath('data.student.active_enrollments', 2)
            ->assertJsonPath('data.student.completed_courses', 1)
            ->assertJsonCount(2, 'data.enrollments')
            ->assertJsonPath('data.enrollments.1.course_title', 'Excel para finanzas')
            ->assertJsonPath('data.enrollments.1.state', 'COMPLETED')
            ->assertJsonPath('data.enrollments.1.progress_percent', 100)
            ->assertJsonPath('data.enrollments.0.course_title', 'Power BI')
            ->assertJsonPath('data.enrollments.0.progress_percent', 0)
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.title', 'Power BI')
            ->assertJsonPath('data.orders.0.state', 'PAID')
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.subject', 'No veo mi curso')
            ->assertJsonPath('meta.grade_scale', 20);

        // Quien no es alumno no tiene ficha
        $admin = User::query()->withoutGlobalScopes()->where('email', 'admin@acme.com')->firstOrFail();
        $this->withToken($this->admin)->getJson($this->api("/students/{$admin->id}"))->assertNotFound();
    }

    public function test_only_roles_with_the_permission_reach_the_directory(): void
    {
        $support = $this->createUser($this->tenant, RoleCatalog::SUPPORT);
        $sales = $this->createUser($this->tenant, RoleCatalog::SALES);
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER);

        $this->withToken($this->login('acme', $support->email))->getJson($this->api("/students/{$this->ana->id}"))->assertOk();
        $this->withToken($this->login('acme', $sales->email))->getJson($this->api('/students'))->assertOk();
        $this->withToken($this->login('acme', $teacher->email))->getJson($this->api('/students'))->assertForbidden();
        $this->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api("/students/{$this->ana->id}"))->assertForbidden();

        // Otra academia no ve a estos alumnos
        $this->provisionTenant('otra', 'admin@otra.com');
        $this->withToken($this->login('otra', 'admin@otra.com'))->getJson($this->tenantUrl('otra', "/students/{$this->ana->id}"))->assertNotFound();
    }
}
