<?php

namespace Tests\Feature\Enrollments;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Curso publicado con una lección, un video, un documento y un cuestionario.
     *
     * @return array{course: string, section: string, document: string, quiz: string}
     */
    private function publishedCourse(string $title = 'Excel para finanzas'): array
    {
        $course = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => $title, 'summary' => 'De cero a experto.', 'language' => 'es'])
            ->assertCreated()->json('data.id');
        $section = $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $resources = $this->api("/courses/{$course}/sections/{$section}/resources");

        $this->withToken($this->admin)->postJson($resources, ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $this->withToken($this->admin)->postJson($resources, ['type' => 'VIDEO', 'title' => 'Clase 1', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
        $document = $this->withToken($this->admin)
            ->post($resources, ['type' => 'DOCUMENT', 'title' => 'Guía', 'file' => $this->upload('guia.pdf', self::PDF)], self::JSON)
            ->assertCreated()->json('data.id');

        $bank = $this->withToken($this->admin)->postJson($this->api('/question-banks'), ['name' => "Banco {$title}"])->json('data.id');
        $question = $this->withToken($this->admin)->postJson($this->api("/question-banks/{$bank}/questions"), [
            'type' => 'TRUE_FALSE', 'statement' => 'Excel es una hoja de cálculo.', 'config' => ['answer' => true],
        ])->json('data.id');
        $quiz = $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/sections/{$section}/activities"), [
            'type' => 'QUIZ', 'title' => 'Evaluación', 'passing_percent' => 55, 'max_attempts' => 3,
        ])->json('data.id');
        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}/activities/{$quiz}/questions"), ['items' => [['question_id' => $question]]])->assertOk();

        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return compact('course', 'section', 'document', 'quiz');
    }

    private function student(array $attributes = []): User
    {
        return $this->createUser($this->tenant, RoleCatalog::STUDENT, $attributes);
    }

    private function enroll(string $course, User ...$students): void
    {
        $this->withToken($this->admin)
            ->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => array_map(fn (User $user) => $user->id, $students)])
            ->assertCreated();
    }

    public function test_students_are_enrolled_listed_and_not_duplicated(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $ana = $this->student(['first_name' => 'Ana', 'last_name' => 'Alumna', 'email' => 'ana@acme.com']);
        $beto = $this->student(['first_name' => 'Beto', 'last_name' => 'Becado', 'email' => 'beto@acme.com']);
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER);
        $endpoint = $this->api("/courses/{$course}/enrollments");

        // Solo quienes tienen un rol de alumno son candidatos (el administrador tiene acceso total)
        $candidates = $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/enrollment-candidates"))->json('data.*.id');
        $this->assertContains($ana->id, $candidates);
        $this->assertNotContains($teacher->id, $candidates);
        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/enrollment-candidates?search=beto"))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'beto@acme.com');

        $this->withToken($this->admin)->postJson($endpoint, ['student_ids' => [$ana->id]])
            ->assertCreated()
            ->assertJsonPath('meta.created', 1)
            ->assertJsonPath('data.0.student.name', 'Ana Alumna')
            ->assertJsonPath('data.0.status', 'ACTIVE');

        // Repetir no duplica: Ana se omite y solo entra Beto
        $this->withToken($this->admin)->postJson($endpoint, ['student_ids' => [$ana->id, $beto->id]])
            ->assertCreated()
            ->assertJsonPath('meta.created', 1)
            ->assertJsonPath('meta.already_enrolled', 1);

        $this->withToken($this->admin)->getJson($endpoint)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertNotContains(
            $ana->id,
            $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/enrollment-candidates"))->json('data.*.id')
        );

        // Un profesor no es matriculable
        $this->withToken($this->admin)->postJson($endpoint, ['student_ids' => [$teacher->id]])
            ->assertStatus(422)->assertJsonStructure(['details' => ['student_ids']]);

        $this->assertSame(2, AuditEvent::query()->where('action', 'enrollments.created')->count());
    }

    public function test_enrollment_requires_a_published_course(): void
    {
        $student = $this->student();
        $draft = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'Borrador', 'language' => 'es'])->json('data.id');

        $this->withToken($this->admin)->postJson($this->api("/courses/{$draft}/enrollments"), ['student_ids' => [$student->id]])
            ->assertStatus(409)->assertJsonPath('code', 'COURSE_INVALID_STATE');

        ['course' => $course] = $this->publishedCourse();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/archive"))->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$student->id]])->assertStatus(409);
    }

    public function test_student_sees_only_the_published_version_of_enrolled_courses(): void
    {
        $data = $this->publishedCourse();
        $other = $this->publishedCourse('Oratoria');
        $student = $this->student(['email' => 'ana@acme.com']);
        $token = $this->login('acme', 'ana@acme.com');

        $this->withToken($token)->getJson($this->api('/me/courses'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson($this->api("/me/courses/{$data['course']}"))->assertNotFound();

        $this->enroll($data['course'], $student);

        $this->withToken($token)->getJson($this->api('/me/courses'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Excel para finanzas')
            ->assertJsonPath('data.0.resources_count', 3)
            ->assertJsonPath('data.0.activities_count', 1);

        $classroom = $this->withToken($token)->getJson($this->api("/me/courses/{$data['course']}"))
            ->assertOk()
            ->assertJsonPath('data.sections.0.resources.0.body', 'Hola.')
            ->assertJsonPath('data.sections.0.resources.1.video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.sections.0.resources.2.file.name', 'guia.pdf')
            ->assertJsonPath('data.sections.0.activities.0.type', 'QUIZ')
            ->assertJsonPath('data.sections.0.activities.0.questions_count', 1)
            ->assertJsonPath('meta.grade_scale', 20)
            ->json('data');

        // Ni las preguntas ni sus respuestas viajan en el aula
        $this->assertStringNotContainsString('hoja de cálculo', json_encode($classroom, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('quiz', $classroom['sections'][0]['activities'][0]);

        // Lo que se edita después no se ve hasta publicar de nuevo
        $this->withToken($this->admin)->postJson($this->api("/courses/{$data['course']}/sections"), ['title' => 'Módulo en preparación'])->assertCreated();
        $this->withToken($token)->getJson($this->api("/me/courses/{$data['course']}"))->assertJsonCount(1, 'data.sections');
        $this->withToken($this->admin)->postJson($this->api("/courses/{$data['course']}/sections/{$data['section']}/resources"), ['type' => 'TEXT', 'title' => 'Extra', 'body' => 'x']);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$data['course']}/publish"))->assertOk();
        $this->withToken($token)->getJson($this->api("/me/courses/{$data['course']}"))->assertJsonCount(4, 'data.sections.0.resources');

        // Un curso en el que no está matriculado no existe para él, ni por el back-office
        $this->withToken($token)->getJson($this->api("/me/courses/{$other['course']}"))->assertNotFound();
        $this->withToken($token)->getJson($this->api("/courses/{$data['course']}"))->assertForbidden();
    }

    public function test_material_is_downloaded_only_by_enrolled_students_from_the_published_version(): void
    {
        $data = $this->publishedCourse();
        $enrolled = $this->student(['email' => 'ana@acme.com']);
        $outsider = $this->student(['email' => 'otro@acme.com']);
        $this->enroll($data['course'], $enrolled);
        $download = $this->api("/me/courses/{$data['course']}/resources/{$data['document']}/download");

        $this->withToken($this->login('acme', 'ana@acme.com'))->get($download)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename=guia.pdf');

        $this->withToken($this->login('acme', 'otro@acme.com'))->getJson($download)->assertNotFound();
        $this->assertNotNull($outsider);

        // Un documento que solo existe en el borrador todavía no se entrega
        $draftOnly = $this->withToken($this->admin)
            ->post($this->api("/courses/{$data['course']}/sections/{$data['section']}/resources"), [
                'type' => 'DOCUMENT', 'title' => 'Anexo', 'file' => $this->upload('anexo.pdf', self::PDF),
            ], self::JSON)->json('data.id');
        $this->withToken($this->login('acme', 'ana@acme.com'))
            ->getJson($this->api("/me/courses/{$data['course']}/resources/{$draftOnly}/download"))->assertNotFound();

        // El documento publicado sigue disponible aunque se quite del borrador
        $this->withToken($this->admin)->deleteJson($this->api("/courses/{$data['course']}/resources/{$data['document']}"))->assertOk();
        $this->withToken($this->login('acme', 'ana@acme.com'))->get($download)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson($download)->assertUnauthorized();
    }

    public function test_cancelling_removes_access_and_allows_enrolling_again(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $student = $this->student(['email' => 'ana@acme.com']);
        $this->enroll($course, $student);
        $token = $this->login('acme', 'ana@acme.com');
        $enrollment = $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/enrollments"))->json('data.0.id');

        $this->withToken($this->admin)->deleteJson($this->api("/courses/{$course}/enrollments/{$enrollment}"))
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->withToken($this->admin)->deleteJson($this->api("/courses/{$course}/enrollments/{$enrollment}"))
            ->assertStatus(409)->assertJsonPath('code', 'ENROLLMENT_NOT_ACTIVE');

        $this->withToken($token)->getJson($this->api('/me/courses'))->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson($this->api("/me/courses/{$course}"))->assertNotFound();

        // La matrícula cancelada queda en el historial y no impide una nueva
        $this->enroll($course, $student);
        $this->withToken($token)->getJson($this->api("/me/courses/{$course}"))->assertOk();
        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/enrollments"))
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.status', 'ACTIVE')
            ->assertJsonPath('data.1.status', 'CANCELLED');
        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/enrollments?status=CANCELLED"))->assertJsonPath('meta.total', 1);

        $this->assertTrue(AuditEvent::query()->where('action', 'enrollments.cancelled')->exists());
    }

    public function test_archived_course_keeps_access_for_enrolled_students(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $student = $this->student(['email' => 'ana@acme.com']);
        $this->enroll($course, $student);

        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/archive"))->assertOk();

        $this->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api("/me/courses/{$course}"))->assertOk();
    }

    public function test_enrollment_management_requires_permissions(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $student = $this->student(['email' => 'ana@acme.com']);
        $this->enroll($course, $student);
        $studentToken = $this->login('acme', 'ana@acme.com');
        $teacherToken = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::TEACHER)->email);
        $enrollment = $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/enrollments"))->json('data.0.id');

        // La lista de un curso la ve también su profesor; para uno que no lo dicta, el curso no existe
        $this->withToken($studentToken)->getJson($this->api("/courses/{$course}/enrollments"))->assertForbidden();
        $this->withToken($teacherToken)->getJson($this->api("/courses/{$course}/enrollments"))->assertNotFound();

        foreach ([$studentToken, $teacherToken] as $token) {
            $this->withToken($token)->getJson($this->api('/enrollments'))->assertForbidden();
            $this->withToken($token)->getJson($this->api("/courses/{$course}/enrollment-candidates"))->assertForbidden();
            $this->withToken($token)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$student->id]])->assertForbidden();
            $this->withToken($token)->deleteJson($this->api("/courses/{$course}/enrollments/{$enrollment}"))->assertForbidden();
        }

        // El portal del alumno es solo para quien tiene ese permiso
        $this->withToken($teacherToken)->getJson($this->api('/me/courses'))->assertForbidden();
    }

    public function test_enrollments_are_isolated_between_tenants(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $student = $this->student(['email' => 'ana@acme.com']);
        $this->enroll($course, $student);

        $beta = $this->provisionTenant('beta', 'admin@beta.com');
        $betaStudent = $this->createUser($beta, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $betaAdmin = $this->login('beta', 'admin@beta.com');

        // Mismo correo en otra organización: es otra persona y no hereda la matrícula
        $this->withToken($this->login('beta', 'ana@acme.com'))->getJson($this->tenantUrl('beta', '/me/courses'))->assertJsonCount(0, 'data');
        $this->withToken($this->login('beta', 'ana@acme.com'))->getJson($this->tenantUrl('beta', "/me/courses/{$course}"))->assertNotFound();

        $this->withToken($betaAdmin)->getJson($this->tenantUrl('beta', "/courses/{$course}/enrollments"))->assertNotFound();
        $this->withToken($betaAdmin)->postJson($this->tenantUrl('beta', "/courses/{$course}/enrollments"), ['student_ids' => [$betaStudent->id]])->assertNotFound();

        // No se puede matricular en acme a un usuario de beta
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$betaStudent->id]])->assertStatus(422);
    }
}
