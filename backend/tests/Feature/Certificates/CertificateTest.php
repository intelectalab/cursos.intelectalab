<?php

namespace Tests\Feature\Certificates;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Certificates\Domain\Models\CertificateVerification;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * S7: certificados. Requisitos → emisión → código → verificación pública.
 */
class CertificateTest extends TestCase
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
     * Curso publicado con dos lecciones.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{course: string, resources: list<string>}
     */
    private function publishedCourse(array $attributes = []): array
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), $attributes + [
            'title' => 'Excel para finanzas', 'language' => 'es', 'duration_hours' => 24, 'issues_certificate' => true,
        ])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');

        $resources = [];
        foreach (['Bienvenida', 'Cierre'] as $title) {
            $resources[] = $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => $title, 'body' => 'Texto.'])->json('data.id');
        }

        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return compact('course', 'resources');
    }

    private function student(string $email = 'ana@acme.com'): User
    {
        return $this->createUser($this->tenant, RoleCatalog::STUDENT, [
            'email' => $email, 'first_name' => 'Ana', 'last_name' => 'Torres', 'doc_type' => 'DNI', 'doc_number' => '45678912',
        ]);
    }

    /**
     * Matricula al alumno y devuelve su matrícula y su sesión.
     *
     * @return array{enrollment: string, token: string}
     */
    private function enroll(User $student, string $course): array
    {
        $enrollment = $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$student->id]])
            ->assertCreated()->json('data.0.id');

        return ['enrollment' => $enrollment, 'token' => $this->login('acme', $student->email)];
    }

    /**
     * @param  list<string>  $resources
     */
    private function complete(string $token, string $course, array $resources): void
    {
        foreach ($resources as $resource) {
            $this->withToken($token)->postJson($this->api("/me/courses/{$course}/resources/{$resource}/complete"))->assertOk();
        }
    }

    public function test_completing_a_course_issues_the_certificate_and_anyone_can_verify_it(): void
    {
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse();
        ['token' => $token] = $this->enroll($this->student(), $course);

        $this->complete($token, $course, [$resources[0]]);
        $this->withToken($token)->getJson($this->api('/me/certificates'))->assertOk()->assertJsonCount(0, 'data');

        $this->complete($token, $course, [$resources[1]]);

        $certificate = $this->withToken($token)->getJson($this->api('/me/certificates'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'ISSUED')
            ->assertJsonPath('data.0.course.title', 'Excel para finanzas')
            ->assertJsonPath('data.0.course.duration_hours', 24)
            ->assertJsonPath('data.0.organization', 'Organización acme')
            ->json('data.0');

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $certificate['code']);
        $this->assertSame("/verify/{$certificate['code']}", $certificate['verify_path']);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        // Verificación pública: con el código tal cual, o sin guiones y en minúsculas
        foreach ([$certificate['code'], strtolower(str_replace('-', '', $certificate['code']))] as $code) {
            $response = $this->getJson($this->api("/certificates/verify/{$code}"))
                ->assertOk()
                ->assertJsonPath('data.valid', true)
                ->assertJsonPath('data.student_name', 'Ana Torres')
                ->assertJsonPath('data.course_title', 'Excel para finanzas')
                ->assertJsonPath('data.organization', 'Organización acme');

            // No expone datos personales más allá del nombre
            $this->assertStringNotContainsString('45678912', $response->getContent());
            $this->assertStringNotContainsString('ana@acme.com', $response->getContent());
        }

        $this->getJson($this->api('/certificates/verify/AAAA-BBBB-CCCC'))->assertNotFound();

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(2, CertificateVerification::query()->where('result', 'VALID')->count());
            $this->assertSame(1, CertificateVerification::query()->where('result', 'NOT_FOUND')->count());
            $this->assertTrue(AuditEvent::query()->where('action', 'certificates.issued')->exists());
            $this->assertSame('DNI 45678912', Certificate::query()->firstOrFail()->snapshot['student_document']);
        });
    }

    public function test_the_certificate_downloads_as_a_pdf(): void
    {
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse();
        $student = $this->student();
        ['token' => $token] = $this->enroll($student, $course);
        $this->complete($token, $course, $resources);

        $this->withToken($this->admin)->putJson($this->api('/certificates/template'), ['signer_name' => 'María Pérez', 'signer_title' => 'Directora académica'])
            ->assertOk()->assertJsonPath('data.signer_name', 'María Pérez');

        $id = $this->withToken($token)->getJson($this->api('/me/certificates'))->json('data.0.id');

        $response = $this->withToken($token)->get($this->api("/me/certificates/{$id}/pdf"))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->withToken($this->admin)->get($this->api("/certificates/{$id}/pdf"))->assertOk();

        // El certificado de otro alumno no existe para mí
        $other = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::STUDENT)->email);
        $this->withToken($other)->getJson($this->api("/me/certificates/{$id}/pdf"))->assertNotFound();
        $this->withToken($other)->getJson($this->api('/me/certificates'))->assertJsonCount(0, 'data');
    }

    public function test_a_certificate_is_not_issued_without_the_requirements(): void
    {
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse();
        ['course' => $plain, 'resources' => $plainResources] = $this->publishedCourse(['title' => 'Sin certificado', 'issues_certificate' => false]);
        $student = $this->student();

        ['enrollment' => $enrollment, 'token' => $token] = $this->enroll($student, $course);
        ['enrollment' => $plainEnrollment] = $this->enroll($student, $plain);

        $issue = fn (string $id) => $this->withToken($this->admin)->postJson($this->api('/certificates/issue'), ['enrollment_id' => $id]);

        // Curso sin completar (TC-CERT-001)
        $this->complete($token, $course, [$resources[0]]);
        $issue($enrollment)->assertStatus(409)->assertJsonPath('code', 'CERTIFICATE_NOT_ELIGIBLE');

        // Curso que no entrega certificado, aunque esté completo
        $this->complete($token, $plain, $plainResources);
        $issue($plainEnrollment)->assertStatus(409)->assertJsonPath('code', 'CERTIFICATE_NOT_ELIGIBLE');
        $this->withToken($token)->getJson($this->api('/me/certificates'))->assertJsonCount(0, 'data');

        // Ya emitido: no se duplica
        $this->complete($token, $course, [$resources[1]]);
        $issue($enrollment)->assertStatus(409)->assertJsonPath('code', 'CERTIFICATE_NOT_ELIGIBLE');

        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => $this->assertSame(1, Certificate::query()->count()));
    }

    public function test_courses_completed_before_the_switch_get_their_certificate_later(): void
    {
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse(['issues_certificate' => false]);
        $student = $this->student();
        ['enrollment' => $enrollment, 'token' => $token] = $this->enroll($student, $course);
        $this->complete($token, $course, $resources);

        $this->withToken($this->admin)->getJson($this->api('/certificates/candidates'))->assertJsonCount(0, 'data');

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Excel para finanzas', 'language' => 'es', 'duration_hours' => 24, 'issues_certificate' => true])
            ->assertOk()
            ->assertJsonPath('data.issues_certificate', true)
            ->assertJsonPath('data.pending_changes', false);

        // El personal lo ve como pendiente de emitir…
        $this->withToken($this->admin)->getJson($this->api('/certificates/candidates'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.enrollment_id', $enrollment)
            ->assertJsonPath('data.0.student.email', 'ana@acme.com');

        // …y el alumno lo recibe al abrir "Mis certificados"
        $this->withToken($token)->getJson($this->api('/me/certificates'))->assertJsonCount(1, 'data');
        $this->withToken($this->admin)->getJson($this->api('/certificates/candidates'))->assertJsonCount(0, 'data');
    }

    public function test_a_revoked_certificate_stops_being_valid_and_can_be_issued_again(): void
    {
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse();
        $student = $this->student();
        ['enrollment' => $enrollment, 'token' => $token] = $this->enroll($student, $course);
        $this->complete($token, $course, $resources);

        $certificate = $this->withToken($this->admin)->getJson($this->api('/certificates'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student.name', 'Ana Torres')
            ->assertJsonPath('data.0.student.email', 'ana@acme.com')
            ->assertJsonPath('data.0.issued_by', null)
            ->json('data.0');

        $this->withToken($this->admin)->postJson($this->api("/certificates/{$certificate['id']}/revoke"), ['reason' => ''])->assertStatus(422);
        $this->withToken($this->admin)->postJson($this->api("/certificates/{$certificate['id']}/revoke"), ['reason' => 'Se emitió a la persona equivocada'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REVOKED')
            ->assertJsonPath('data.revoke_reason', 'Se emitió a la persona equivocada');
        $this->withToken($this->admin)->postJson($this->api("/certificates/{$certificate['id']}/revoke"), ['reason' => 'Otra vez'])
            ->assertStatus(409)->assertJsonPath('code', 'CERTIFICATE_REVOKED');

        // Para el alumno deja de existir y no se repone solo
        $this->withToken($token)->getJson($this->api('/me/certificates'))->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson($this->api("/me/certificates/{$certificate['id']}/pdf"))->assertNotFound();
        $this->withToken($this->admin)->getJson($this->api("/certificates/{$certificate['id']}/pdf"))->assertStatus(409);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->api("/certificates/verify/{$certificate['code']}"))
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.status', 'REVOKED');

        // El personal puede emitir uno nuevo, con otro código
        $this->withToken($this->admin)->getJson($this->api('/certificates/candidates'))->assertJsonCount(1, 'data');
        $new = $this->withToken($this->admin)->postJson($this->api('/certificates/issue'), ['enrollment_id' => $enrollment])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.issued_by', 'Ada Admin')
            ->json('data.code');

        $this->assertNotSame($certificate['code'], $new);
        $this->withToken($this->admin)->getJson($this->api('/certificates?status=REVOKED'))->assertJsonCount(1, 'data');
        $this->withToken($this->admin)->getJson($this->api('/certificates?search='.strtolower($new)))->assertJsonCount(1, 'data');
        $this->withToken($this->admin)->getJson($this->api('/certificates?search=torres'))->assertJsonCount(2, 'data');
    }

    public function test_the_signature_is_uploaded_validated_and_private(): void
    {
        $image = imagecreatetruecolor(60, 20);
        $path = tempnam(sys_get_temp_dir(), 'firma').'.png';
        imagepng($image, $path);

        $this->withToken($this->admin)->post($this->api('/certificates/template/signature'), ['file' => new UploadedFile($path, 'firma.png', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_signature', true);
        $this->withToken($this->admin)->get($this->api('/certificates/template/signature'))->assertOk();

        $text = tempnam(sys_get_temp_dir(), 'firma').'.png';
        file_put_contents($text, 'no soy una imagen');
        $this->withToken($this->admin)->post($this->api('/certificates/template/signature'), ['file' => new UploadedFile($text, 'firma.png', null, null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $student = $this->login('acme', $this->student()->email);
        $this->withToken($student)->getJson($this->api('/certificates/template/signature'))->assertForbidden();

        $this->withToken($this->admin)->deleteJson($this->api('/certificates/template/signature'))->assertOk()->assertJsonPath('data.has_signature', false);
        $this->withToken($this->admin)->getJson($this->api('/certificates/template/signature'))->assertNotFound();
    }

    public function test_certificates_are_isolated_and_require_permission(): void
    {
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse();
        $student = $this->student();
        ['enrollment' => $enrollment, 'token' => $token] = $this->enroll($student, $course);
        $this->complete($token, $course, $resources);
        $certificate = $this->withToken($token)->getJson($this->api('/me/certificates'))->json('data.0');

        // El alumno no gestiona certificados
        $this->withToken($token)->getJson($this->api('/certificates'))->assertForbidden();
        $this->withToken($token)->postJson($this->api('/certificates/issue'), ['enrollment_id' => $enrollment])->assertForbidden();
        $this->withToken($token)->postJson($this->api("/certificates/{$certificate['id']}/revoke"), ['reason' => 'Porque sí'])->assertForbidden();
        $this->withToken($token)->putJson($this->api('/certificates/template'), ['signer_name' => 'Yo'])->assertForbidden();

        $teacher = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::TEACHER)->email);
        $this->withToken($teacher)->getJson($this->api('/certificates'))->assertForbidden();
        $this->withToken($teacher)->getJson($this->api('/me/certificates'))->assertForbidden();

        // Otra organización no ve ni verifica este certificado
        $this->provisionTenant('beta', 'admin@beta.com');
        $beta = $this->login('beta', 'admin@beta.com');
        $this->withToken($beta)->getJson($this->tenantUrl('beta', '/certificates'))->assertJsonCount(0, 'data');
        $this->withToken($beta)->getJson($this->tenantUrl('beta', "/certificates/{$certificate['id']}/pdf"))->assertNotFound();
        $this->withToken($beta)->postJson($this->tenantUrl('beta', '/certificates/issue'), ['enrollment_id' => $enrollment])->assertStatus(422);
        $this->getJson($this->tenantUrl('beta', "/certificates/verify/{$certificate['code']}"))->assertNotFound();
    }
}
