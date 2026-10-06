<?php

namespace Tests\Feature\Enrollments;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S4: vigencia de la matrícula, gestión general, curso completado y portal del alumno.
 */
class EnrollmentValidityTest extends TestCase
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
     * Curso publicado con dos lecciones y una tarea.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{course: string, section: string, resources: list<string>, task: string}
     */
    private function publishedCourse(array $attributes = []): array
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), $attributes + ['title' => 'Excel para finanzas', 'language' => 'es'])
            ->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');

        $resources = [];
        foreach (['Bienvenida', 'Cierre'] as $title) {
            $resources[] = $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => $title, 'body' => 'Texto reservado.'])->json('data.id');
        }

        $task = $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/activities"), [
            'type' => 'TASK', 'title' => 'Presupuesto', 'description' => 'Consigna', 'allow_text' => true, 'allow_file' => false,
        ])->json('data.id');

        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return compact('course', 'section', 'resources', 'task');
    }

    private function student(string $email = 'ana@acme.com', array $attributes = []): User
    {
        return $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => $email] + $attributes);
    }

    public function test_course_access_days_set_the_default_validity_of_new_enrollments(): void
    {
        $this->travelTo('2026-03-10 09:00:00');

        ['course' => $course] = $this->publishedCourse(['access_days' => 30]);
        ['course' => $open] = $this->publishedCourse(['title' => 'Sin vencimiento']);
        $student = $this->student();

        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}"))->assertJsonPath('data.access_days', 30);

        // Con días de acceso en el curso, la vigencia se calcula sola; sin ellos, no vence
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$student->id]])
            ->assertCreated()
            ->assertJsonPath('data.0.starts_at', null)
            ->assertJsonPath('data.0.ends_at', '2026-04-09')
            ->assertJsonPath('data.0.state', 'ACTIVE');
        $this->withToken($this->admin)->postJson($this->api('/enrollments'), ['course_id' => $open, 'student_ids' => [$student->id]])
            ->assertCreated()->assertJsonPath('data.0.ends_at', null);

        // Fechas explícitas mandan sobre el valor del curso; el fin no puede ser anterior al inicio
        // Nombre fijo: uno al azar que contenga "ana" (Mariana, Santana) se colaría en la búsqueda del final
        $other = $this->student('beto@acme.com', ['first_name' => 'Beto', 'last_name' => 'Ruiz']);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), [
            'student_ids' => [$other->id], 'starts_at' => '2026-04-01', 'ends_at' => '2026-03-01',
        ])->assertStatus(422)->assertJsonStructure(['details' => ['ends_at']]);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), [
            'student_ids' => [$other->id], 'starts_at' => '2026-04-01', 'ends_at' => '2026-12-31',
        ])->assertCreated()->assertJsonPath('data.0.ends_at', '2026-12-31')->assertJsonPath('data.0.state', 'UPCOMING');

        // Cambiar los días del curso no genera cambios por publicar ni altera matrículas ya hechas
        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Excel para finanzas', 'language' => 'es', 'access_days' => 90])
            ->assertOk()->assertJsonPath('data.access_days', 90)->assertJsonPath('data.pending_changes', false);
        $this->withToken($this->admin)->getJson($this->api("/enrollments?course_id={$course}&search=ana"))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.ends_at', '2026-04-09');
    }

    public function test_access_requires_an_enrollment_within_its_validity(): void
    {
        $this->travelTo('2026-03-10 09:00:00');

        $data = $this->publishedCourse(['access_days' => 30]);
        $student = $this->student();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$data['course']}/enrollments"), ['student_ids' => [$student->id]])->assertCreated();
        $token = $this->login('acme', 'ana@acme.com');
        $aula = $this->api("/me/courses/{$data['course']}");

        // El último día todavía entra
        $this->travelTo('2026-04-09 22:00:00');
        $token = $this->login('acme', 'ana@acme.com');
        $this->withToken($token)->getJson($aula)->assertOk();

        // Al día siguiente ya no: ni el aula, ni las actividades, ni marcar avance
        $this->travelTo('2026-04-10 08:00:00');
        $token = $this->login('acme', 'ana@acme.com');
        $this->withToken($token)->getJson($aula)
            ->assertForbidden()
            ->assertJsonPath('code', 'ENROLLMENT_EXPIRED')
            ->assertJsonPath('message', 'Tu acceso a este curso venció el 9 de abril de 2026. Comunícate con tu academia para extenderlo.');
        $this->withToken($token)->getJson("{$aula}/activities")->assertForbidden();
        $this->withToken($token)->postJson("{$aula}/resources/{$data['resources'][0]}/complete")->assertForbidden();
        $this->withToken($token)->post("{$aula}/activities/{$data['task']}/submission", ['body' => 'Tarde.'], ['Accept' => 'application/json'])->assertForbidden();

        // Sigue en "Mis cursos", marcado como vencido, y no vuelve a aparecer como disponible
        $this->withToken($token)->getJson($this->api('/me/courses'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.state', 'EXPIRED');
        $this->withToken($token)->getJson($this->api('/me/available-courses'))->assertJsonCount(0, 'data');

        // El personal extiende la vigencia y el acceso vuelve
        $enrollment = $this->withToken($this->admin)->getJson($this->api('/enrollments?state=EXPIRED'))->assertJsonPath('meta.total', 1)->json('data.0.id');
        $this->withToken($this->admin)->putJson($this->api("/enrollments/{$enrollment}"), ['ends_at' => '2026-06-30'])
            ->assertOk()->assertJsonPath('data.state', 'ACTIVE')->assertJsonPath('data.ends_at', '2026-06-30');
        $this->withToken($token)->getJson($aula)->assertOk();

        // Quitar las fechas deja la matrícula sin límite
        $this->withToken($this->admin)->putJson($this->api("/enrollments/{$enrollment}"), [])->assertOk()->assertJsonPath('data.ends_at', null);

        // Una matrícula que aún no empieza tampoco da acceso
        $this->withToken($this->admin)->putJson($this->api("/enrollments/{$enrollment}"), ['starts_at' => '2026-05-01'])->assertOk()->assertJsonPath('data.state', 'UPCOMING');
        $this->withToken($token)->getJson($aula)->assertForbidden()->assertJsonPath('code', 'ENROLLMENT_NOT_STARTED');

        $this->assertSame(3, AuditEvent::query()->where('action', 'enrollments.updated')->count());
    }

    public function test_enrollments_are_managed_from_the_general_list(): void
    {
        $this->travelTo('2026-03-10 09:00:00');

        ['course' => $excel] = $this->publishedCourse();
        ['course' => $oratoria] = $this->publishedCourse(['title' => 'Oratoria']);
        $ana = $this->student('ana@acme.com', ['first_name' => 'Ana', 'last_name' => 'Alumna']);
        $beto = $this->student('beto@acme.com', ['first_name' => 'Beto', 'last_name' => 'Becado']);

        $this->withToken($this->admin)->postJson($this->api('/enrollments'), ['course_id' => $excel, 'student_ids' => [$ana->id, $beto->id], 'ends_at' => '2026-03-15'])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api('/enrollments'), ['course_id' => $oratoria, 'student_ids' => [$ana->id], 'starts_at' => '2026-06-01'])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api('/enrollments'), ['course_id' => fake()->uuid(), 'student_ids' => [$ana->id]])->assertStatus(422);

        $this->withToken($this->admin)->getJson($this->api('/enrollments'))
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data' => [['id', 'state', 'starts_at', 'ends_at', 'progress_percent', 'course' => ['id', 'title'], 'student' => ['name', 'email']]]]);
        $this->withToken($this->admin)->getJson($this->api("/enrollments?course_id={$excel}"))->assertJsonPath('meta.total', 2);
        $this->withToken($this->admin)->getJson($this->api('/enrollments?search=becado'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.student.name', 'Beto Becado');
        $this->withToken($this->admin)->getJson($this->api('/enrollments?state=UPCOMING'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.course.title', 'Oratoria');
        $this->withToken($this->admin)->getJson($this->api('/enrollments/options'))
            ->assertOk()->assertJsonCount(2, 'data.courses')->assertJsonPath('data.courses.0.open', true);

        // Cancelar desde la lista general; el filtro por situación lo refleja
        $enrollment = $this->withToken($this->admin)->getJson($this->api('/enrollments?search=becado'))->json('data.0.id');
        $this->withToken($this->admin)->deleteJson($this->api("/enrollments/{$enrollment}"))->assertOk()->assertJsonPath('data.state', 'CANCELLED');
        $this->withToken($this->admin)->putJson($this->api("/enrollments/{$enrollment}"), ['ends_at' => '2026-12-31'])->assertStatus(409)->assertJsonPath('code', 'ENROLLMENT_NOT_ACTIVE');
        $this->withToken($this->admin)->getJson($this->api('/enrollments?state=CANCELLED'))->assertJsonPath('meta.total', 1);

        $this->travelTo('2026-03-20 09:00:00');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($this->admin)->getJson($this->api('/enrollments?state=EXPIRED'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.student.name', 'Ana Alumna');
        $this->withToken($this->admin)->getJson($this->api('/enrollments?state=ACTIVE'))->assertJsonPath('meta.total', 0);
    }

    public function test_support_reads_enrollments_and_teachers_see_their_course_students(): void
    {
        ['course' => $course] = $this->publishedCourse();
        ['course' => $other] = $this->publishedCourse(['title' => 'Oratoria']);
        $student = $this->student();
        $this->withToken($this->admin)->postJson($this->api('/enrollments'), ['course_id' => $course, 'student_ids' => [$student->id]])->assertCreated();
        $enrollment = $this->withToken($this->admin)->getJson($this->api('/enrollments'))->json('data.0.id');

        // Soporte consulta, pero no matricula, ni cambia vigencias, ni cancela (Maestro §8.1)
        $support = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::SUPPORT)->email);
        $this->withToken($support)->getJson($this->api('/enrollments'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($support)->getJson($this->api('/enrollments/options'))->assertOk();
        $this->withToken($support)->postJson($this->api('/enrollments'), ['course_id' => $course, 'student_ids' => [$student->id]])->assertForbidden();
        $this->withToken($support)->putJson($this->api("/enrollments/{$enrollment}"), ['ends_at' => '2030-01-01'])->assertForbidden();
        $this->withToken($support)->deleteJson($this->api("/enrollments/{$enrollment}"))->assertForbidden();

        // El profesor ve a los alumnos de su curso, y solo de ese
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER);
        $teacherToken = $this->login('acme', $teacher->email);
        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}/teachers"), ['user_ids' => [$teacher->id]])->assertOk();

        $this->withToken($teacherToken)->getJson($this->api("/courses/{$course}/enrollments"))->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($teacherToken)->getJson($this->api("/courses/{$other}/enrollments"))->assertNotFound();
        $this->withToken($teacherToken)->getJson($this->api('/enrollments'))->assertForbidden();
        $this->withToken($teacherToken)->deleteJson($this->api("/courses/{$course}/enrollments/{$enrollment}"))->assertForbidden();

        // Una matrícula no se cancela a través de otro curso
        $this->withToken($this->admin)->deleteJson($this->api("/courses/{$other}/enrollments/{$enrollment}"))->assertNotFound();
    }

    public function test_enrollment_is_marked_completed_when_the_student_reaches_one_hundred_percent(): void
    {
        $data = $this->publishedCourse();
        $student = $this->student();
        $this->withToken($this->admin)->postJson($this->api('/enrollments'), ['course_id' => $data['course'], 'student_ids' => [$student->id]])->assertCreated();
        $token = $this->login('acme', 'ana@acme.com');
        $aula = $this->api("/me/courses/{$data['course']}");

        foreach ($data['resources'] as $resource) {
            $this->withToken($token)->postJson("{$aula}/resources/{$resource}/complete")->assertOk();
        }
        $this->withToken($this->admin)->getJson($this->api('/enrollments'))->assertJsonPath('data.0.state', 'ACTIVE')->assertJsonPath('data.0.progress_percent', 66);

        $this->withToken($token)->post("{$aula}/activities/{$data['task']}/submission", ['body' => 'Entrega.'], ['Accept' => 'application/json'])->assertOk();

        $this->withToken($this->admin)->getJson($this->api('/enrollments?state=COMPLETED'))
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.progress_percent', 100);
        $this->withToken($token)->getJson($this->api('/me/courses'))->assertJsonPath('data.0.state', 'COMPLETED');

        // Publicar contenido nuevo baja el avance, pero el curso ya quedó completado
        $this->withToken($this->admin)->postJson($this->api("/courses/{$data['course']}/sections/{$data['section']}/resources"), ['type' => 'TEXT', 'title' => 'Extra', 'body' => 'x'])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$data['course']}/publish"))->assertOk();
        $this->withToken($this->admin)->getJson($this->api('/enrollments'))
            ->assertJsonPath('data.0.state', 'COMPLETED')
            ->assertJsonPath('data.0.progress_percent', 75);
        $this->withToken($token)->getJson($aula)->assertOk();
    }

    public function test_student_home_shows_pending_work_and_recent_grades(): void
    {
        $data = $this->publishedCourse();
        $student = $this->student();
        $this->withToken($this->admin)->postJson($this->api('/enrollments'), ['course_id' => $data['course'], 'student_ids' => [$student->id]])->assertCreated();
        $token = $this->login('acme', 'ana@acme.com');

        $this->withToken($token)->getJson($this->api('/me/overview'))
            ->assertOk()
            ->assertJsonCount(1, 'data.courses')
            ->assertJsonPath('data.courses.0.progress_percent', 0)
            ->assertJsonPath('data.pending_total', 1)
            ->assertJsonPath('data.pending.0.title', 'Presupuesto')
            ->assertJsonPath('data.pending.0.course_title', 'Excel para finanzas')
            ->assertJsonCount(0, 'data.recent_grades')
            ->assertJsonPath('meta.grade_scale', 20);

        $this->withToken($token)->post($this->api("/me/courses/{$data['course']}/activities/{$data['task']}/submission"), ['body' => 'Entrega.'], ['Accept' => 'application/json'])->assertOk();
        $submission = $this->withToken($this->admin)->getJson($this->api("/courses/{$data['course']}/submissions"))->json('data.0.id');
        $this->withToken($this->admin)->postJson($this->api("/courses/{$data['course']}/submissions/{$submission}/grade"), ['grade_percent' => 90])->assertOk();

        $this->withToken($token)->getJson($this->api('/me/overview'))
            ->assertJsonPath('data.pending_total', 0)
            ->assertJsonPath('data.recent_grades.0.type', 'TASK')
            ->assertJsonPath('data.recent_grades.0.title', 'Presupuesto')
            ->assertJsonPath('data.recent_grades.0.grade_percent', 90);

        // Un profesor no tiene portal de alumno
        $teacher = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::TEACHER)->email);
        $this->withToken($teacher)->getJson($this->api('/me/overview'))->assertForbidden();
    }

    public function test_available_courses_show_the_card_but_never_the_content(): void
    {
        $mine = $this->publishedCourse();
        $other = $this->publishedCourse(['title' => 'Oratoria', 'summary' => 'Habla en público.', 'access_days' => 60]);
        $draft = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'En preparación', 'language' => 'es'])->json('data.id');
        $archived = $this->publishedCourse(['title' => 'Retirado']);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$archived['course']}/archive"))->assertOk();

        $student = $this->student();
        $this->withToken($this->admin)->postJson($this->api('/enrollments'), ['course_id' => $mine['course'], 'student_ids' => [$student->id]])->assertCreated();
        $token = $this->login('acme', 'ana@acme.com');

        // Solo los publicados en los que no está: ni el suyo, ni el borrador, ni el archivado
        $this->withToken($token)->getJson($this->api('/me/available-courses'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Oratoria')
            ->assertJsonPath('data.0.resources_count', 2);

        $card = $this->withToken($token)->getJson($this->api("/me/available-courses/{$other['course']}"))
            ->assertOk()
            ->assertJsonPath('data.access_days', 60)
            ->assertJsonPath('data.sections.0.title', 'Módulo 1')
            ->assertJsonPath('data.sections.0.items.0.title', 'Bienvenida')
            ->assertJsonPath('data.sections.0.items.2.kind', 'ACTIVITY')
            ->json('data');

        // El temario lista títulos, no el contenido de las lecciones
        $this->assertStringNotContainsString('Texto reservado', json_encode($card, JSON_UNESCAPED_UNICODE));

        foreach ([$mine['course'], $draft, $archived['course']] as $hidden) {
            $this->withToken($token)->getJson($this->api("/me/available-courses/{$hidden}"))->assertNotFound();
        }

        // Ver la ficha no da acceso al aula
        $this->withToken($token)->getJson($this->api("/me/courses/{$other['course']}"))->assertNotFound();

        // Tras cancelar su matrícula, el curso vuelve a estar disponible
        $enrollment = $this->withToken($this->admin)->getJson($this->api('/enrollments'))->json('data.0.id');
        $this->withToken($this->admin)->deleteJson($this->api("/enrollments/{$enrollment}"))->assertOk();
        $this->withToken($token)->getJson($this->api('/me/available-courses'))->assertJsonCount(2, 'data');
    }
}
