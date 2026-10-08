<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de docentes y ficha con sus cursos y su carga de calificación.
 */
class TeacherDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $course;

    private string $task;

    private User $rosa;

    private User $saul;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $admin = fn () => $this->withToken($this->admin);

        // Curso publicado con una tarea
        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es'])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $this->task = $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/activities"), [
            'type' => 'TASK', 'title' => 'Presupuesto', 'description' => 'Arma un presupuesto.', 'allow_text' => true, 'allow_file' => false,
        ])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa@acme.com']);
        $this->saul = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Saúl', 'last_name' => 'Salas', 'email' => 'saul@acme.com']);
        $admin()->putJson($this->api("/courses/{$this->course}/teachers"), ['user_ids' => [$this->rosa->id]])->assertOk();

        // Una alumna matriculada entrega la tarea
        $student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $admin()->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$student->id]])->assertCreated();
        $this->withToken($this->login('acme', 'ana@acme.com'))
            ->postJson($this->api("/me/courses/{$this->course}/activities/{$this->task}/submission"), ['body' => 'Mi presupuesto.'])->assertOk();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    public function test_teachers_are_listed_with_their_courses_and_grading_load(): void
    {
        // Ni el administrador ni la alumna son docentes
        $this->withToken($this->admin)->getJson($this->api('/teachers'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.counts.WITH_COURSES', 1)
            ->assertJsonPath('meta.counts.WITHOUT_COURSES', 1)
            ->assertJsonPath('meta.counts.INACTIVE', 0)
            ->assertJsonPath('data.0.name', 'Rosa Quispe')
            ->assertJsonPath('data.0.courses_count', 1)
            ->assertJsonPath('data.0.pending_submissions', 1)
            ->assertJsonPath('data.1.name', 'Saúl Salas')
            ->assertJsonPath('data.1.courses_count', 0)
            ->assertJsonPath('data.1.pending_submissions', 0);

        $this->withToken($this->admin)->getJson($this->api('/teachers?filter=WITHOUT_COURSES'))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'saul@acme.com');
        $this->withToken($this->admin)->getJson($this->api('/teachers?search=quis'))->assertJsonPath('meta.total', 1);

        // Un curso archivado deja de contar como carga
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/archive"))->assertOk();
        $this->withToken($this->admin)->getJson($this->api('/teachers'))
            ->assertJsonPath('meta.counts.WITH_COURSES', 0)
            ->assertJsonPath('data.0.courses_count', 0)
            ->assertJsonPath('data.0.pending_submissions', 0);
    }

    public function test_teacher_profile_shows_courses_pending_work_and_recent_grading(): void
    {
        $profile = $this->api("/teachers/{$this->rosa->id}");

        $this->withToken($this->admin)->getJson($profile)
            ->assertOk()
            ->assertJsonPath('data.teacher.name', 'Rosa Quispe')
            ->assertJsonCount(1, 'data.courses')
            ->assertJsonPath('data.courses.0.title', 'Excel para finanzas')
            ->assertJsonPath('data.courses.0.students', 1)
            ->assertJsonPath('data.courses.0.pending_submissions', 1)
            ->assertJsonPath('data.students_total', 1)
            ->assertJsonPath('data.graded_recently', 0)
            ->assertJsonPath('meta.recent_days', 30);
        $this->assertNotNull($this->withToken($this->admin)->getJson($profile)->json('data.courses.0.oldest_pending_at'));

        // Rosa califica: baja lo pendiente y sube lo calificado
        $rosa = $this->login('acme', 'rosa@acme.com');
        $submission = $this->withToken($rosa)->getJson($this->api("/courses/{$this->course}/submissions"))->json('data.0.id');
        $this->withToken($rosa)->postJson($this->api("/courses/{$this->course}/submissions/{$submission}/grade"), ['grade_percent' => 80])->assertOk();

        $this->withToken($this->admin)->getJson($profile)
            ->assertJsonPath('data.courses.0.pending_submissions', 0)
            ->assertJsonPath('data.courses.0.oldest_pending_at', null)
            ->assertJsonPath('data.teacher.pending_submissions', 0)
            ->assertJsonPath('data.graded_recently', 1);

        // Quien no es docente no tiene ficha; el propio profesor no entra al directorio
        $admin = User::query()->withoutGlobalScopes()->where('email', 'admin@acme.com')->firstOrFail();
        $this->withToken($this->admin)->getJson($this->api("/teachers/{$admin->id}"))->assertNotFound();
        $this->withToken($rosa)->getJson($this->api('/teachers'))->assertForbidden();
        $this->withToken($rosa)->getJson($profile)->assertForbidden();

        // Otra academia no ve a estos docentes
        $this->provisionTenant('otra', 'admin@otra.com');
        $this->withToken($this->login('otra', 'admin@otra.com'))->getJson($this->tenantUrl('otra', "/teachers/{$this->rosa->id}"))->assertNotFound();
    }
}
