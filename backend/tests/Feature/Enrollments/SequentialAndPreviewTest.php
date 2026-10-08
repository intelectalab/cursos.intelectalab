<?php

namespace Tests\Feature\Enrollments;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Avance secuencial por secciones y vista previa del aula para el personal.
 */
class SequentialAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $student;

    private string $course;

    private string $firstSection;

    private string $welcome;

    private string $closing;

    private string $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $this->buildCourse();

        $student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$student->id]])->assertCreated();
        $this->student = $this->login('acme', 'ana@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * Curso publicado de dos secciones: la primera con una lección; la
     * segunda con otra lección y una tarea.
     */
    private function buildCourse(): void
    {
        $admin = fn () => $this->withToken($this->admin);

        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es'])->json('data.id');
        $this->firstSection = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $second = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 2'])->json('data.id');

        $this->welcome = $admin()->postJson($this->api("/courses/{$this->course}/sections/{$this->firstSection}/resources"), [
            'type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.',
        ])->json('data.id');
        $this->closing = $admin()->postJson($this->api("/courses/{$this->course}/sections/{$second}/resources"), [
            'type' => 'TEXT', 'title' => 'Cierre', 'body' => 'Contenido del segundo módulo.',
        ])->json('data.id');
        $this->task = $admin()->postJson($this->api("/courses/{$this->course}/sections/{$second}/activities"), [
            'type' => 'TASK', 'title' => 'Presupuesto', 'description' => 'Arma un presupuesto.', 'allow_text' => true, 'allow_file' => false,
        ])->json('data.id');

        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();
    }

    private function setSequential(bool $sequential): void
    {
        $this->withToken($this->admin)->putJson($this->api("/courses/{$this->course}"), [
            'title' => 'Excel para finanzas', 'language' => 'es', 'sequential' => $sequential,
        ])->assertOk()->assertJsonPath('data.sequential', $sequential);
    }

    public function test_sequential_course_opens_each_section_when_the_previous_one_is_complete(): void
    {
        $me = $this->api("/me/courses/{$this->course}");

        // Sin el interruptor, todo está abierto
        $this->withToken($this->student)->getJson($me)
            ->assertOk()
            ->assertJsonPath('data.sequential', false)
            ->assertJsonPath('data.sections.1.locked', false);

        // El interruptor vale al guardarlo, sin publicar
        $this->setSequential(true);

        // La segunda sección queda cerrada y su contenido no sale del servidor
        $this->withToken($this->student)->getJson($me)
            ->assertOk()
            ->assertJsonPath('data.sequential', true)
            ->assertJsonPath('data.sections.0.locked', false)
            ->assertJsonPath('data.sections.1.locked', true)
            ->assertJsonPath('data.sections.1.resources.0.title', 'Cierre')
            ->assertJsonPath('data.sections.1.resources.0.body', null)
            ->assertJsonPath('data.sections.1.activities.0.description', null);

        $this->withToken($this->student)->postJson("{$me}/resources/{$this->closing}/complete")
            ->assertForbidden()->assertJsonPath('code', 'SECTION_LOCKED');
        $this->withToken($this->student)->getJson("{$me}/activities/{$this->task}")
            ->assertForbidden()->assertJsonPath('code', 'SECTION_LOCKED');
        $this->withToken($this->student)->postJson("{$me}/activities/{$this->task}/submission", ['body' => 'Me adelanto.'])
            ->assertForbidden()->assertJsonPath('code', 'SECTION_LOCKED');

        // Al completar la primera sección se abre la segunda
        $this->withToken($this->student)->postJson("{$me}/resources/{$this->welcome}/complete")->assertOk();

        $this->withToken($this->student)->getJson($me)
            ->assertJsonPath('data.sections.1.locked', false)
            ->assertJsonPath('data.sections.1.resources.0.body', 'Contenido del segundo módulo.');
        $this->withToken($this->student)->postJson("{$me}/resources/{$this->closing}/complete")->assertOk();
        $this->withToken($this->student)->postJson("{$me}/activities/{$this->task}/submission", ['body' => 'Ahora sí.'])->assertOk();

        // Apagarlo vuelve a abrir todo para quien recién empieza
        $this->setSequential(false);
        $other = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'beto@acme.com']);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$other->id]])->assertCreated();
        $this->withToken($this->login('acme', 'beto@acme.com'))->postJson("{$me}/resources/{$this->closing}/complete")->assertOk();
    }

    public function test_staff_previews_the_classroom_from_the_published_version_or_the_draft(): void
    {
        $preview = $this->api("/courses/{$this->course}/preview");

        // Un cambio en el borrador que los alumnos aún no ven
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/sections/{$this->firstSection}/resources"), [
            'type' => 'TEXT', 'title' => 'Lección nueva', 'body' => 'Aún sin publicar.',
        ])->assertCreated();
        $this->setSequential(true);

        // Por defecto, lo publicado: nada cerrado, con todo su contenido
        $this->withToken($this->admin)->getJson($preview)
            ->assertOk()
            ->assertJsonPath('meta.source', 'published')
            ->assertJsonPath('meta.pending_changes', true)
            ->assertJsonPath('data.title', 'Excel para finanzas')
            ->assertJsonCount(1, 'data.sections.0.resources')
            ->assertJsonPath('data.sections.1.locked', false)
            ->assertJsonPath('data.sections.1.resources.0.body', 'Contenido del segundo módulo.')
            ->assertJsonPath('data.sections.1.activities.0.title', 'Presupuesto');

        $this->withToken($this->admin)->getJson("{$preview}?source=draft")
            ->assertOk()
            ->assertJsonPath('meta.source', 'draft')
            ->assertJsonCount(2, 'data.sections.0.resources')
            ->assertJsonPath('data.sections.0.resources.1.title', 'Lección nueva');

        // Un curso sin publicar solo tiene borrador
        $draft = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'En preparación', 'language' => 'es'])->json('data.id');
        $this->withToken($this->admin)->getJson($this->api("/courses/{$draft}/preview"))
            ->assertOk()->assertJsonPath('meta.source', 'draft')->assertJsonPath('meta.has_published', false);
        $this->withToken($this->admin)->getJson($this->api("/courses/{$draft}/preview?source=published"))->assertNotFound();

        // El profesor solo en sus cursos; el alumno, nunca
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER);
        $teacherToken = $this->login('acme', $teacher->email);
        $this->withToken($teacherToken)->getJson($preview)->assertNotFound();
        $this->withToken($this->admin)->putJson($this->api("/courses/{$this->course}/teachers"), ['user_ids' => [$teacher->id]])->assertOk();
        $this->withToken($teacherToken)->getJson($preview)->assertOk();
        $this->withToken($this->student)->getJson($preview)->assertForbidden();
    }
}
