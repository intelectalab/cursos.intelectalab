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

    public function test_a_course_with_a_passing_grade_only_certifies_students_who_reach_it(): void
    {
        $admin = fn () => $this->withToken($this->admin);

        // Curso con certificado y nota mínima de 60 %, cuya única nota es una tarea
        $course = $admin()->postJson($this->api('/courses'), [
            'title' => 'Excel para finanzas', 'language' => 'es', 'issues_certificate' => true, 'passing_percent' => 60,
        ])->assertCreated()->assertJsonPath('data.passing_percent', 60)->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $task = $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/activities"), [
            'type' => 'TASK', 'title' => 'Presupuesto', 'description' => 'Arma un presupuesto.', 'allow_text' => true, 'allow_file' => false,
        ])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();
        $admin()->postJson($this->api('/courses'), ['title' => 'Otro', 'language' => 'es', 'passing_percent' => 101])->assertStatus(422);

        ['enrollment' => $enrollment, 'token' => $token] = $this->enroll($this->student(), $course);

        // Entregar la tarea completa el curso, pero sin nota todavía no hay certificado
        $this->withToken($token)->postJson($this->api("/me/courses/{$course}/activities/{$task}/submission"), ['body' => 'Mi presupuesto.'])->assertOk();
        $this->assertSame(0, Certificate::query()->withoutGlobalScopes()->count());
        $admin()->getJson($this->api('/certificates/candidates'))->assertJsonCount(0, 'data');
        $admin()->postJson($this->api('/certificates/issue'), ['enrollment_id' => $enrollment])
            ->assertStatus(409)->assertJsonPath('code', 'CERTIFICATE_NOT_ELIGIBLE');
        $this->withToken($token)->getJson($this->api("/me/courses/{$course}/grades"))
            ->assertJsonPath('data.passing_percent', 60)
            ->assertJsonPath('data.passed', false);

        // Con una nota por debajo de la mínima, tampoco
        $submission = $admin()->getJson($this->api("/courses/{$course}/submissions"))->json('data.0.id');
        $admin()->postJson($this->api("/courses/{$course}/submissions/{$submission}/grade"), ['grade_percent' => 50])->assertOk();
        $this->assertSame(0, Certificate::query()->withoutGlobalScopes()->count());
        $admin()->getJson($this->api("/courses/{$course}/tracking"))
            ->assertJsonPath('meta.passing_percent', 60)
            ->assertJsonPath('data.students.0.passed', false);

        // Al alcanzarla, el certificado se emite solo
        $admin()->postJson($this->api("/courses/{$course}/submissions/{$submission}/grade"), ['grade_percent' => 80])->assertOk();
        $this->assertSame(1, Certificate::query()->withoutGlobalScopes()->where('enrollment_id', $enrollment)->count());
        $this->withToken($token)->getJson($this->api("/me/courses/{$course}/grades"))->assertJsonPath('data.passed', true);
        $admin()->getJson($this->api("/courses/{$course}/tracking"))->assertJsonPath('data.students.0.passed', true);
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

    public function test_pending_certificates_are_issued_together_and_the_rest_are_skipped(): void
    {
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse(['issues_certificate' => false]);
        $done = [];
        foreach (['ana@acme.com' => '45678912', 'beto@acme.com' => '45678913'] as $email => $document) {
            $student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => $email, 'doc_type' => 'DNI', 'doc_number' => $document]);
            ['enrollment' => $enrollment, 'token' => $token] = $this->enroll($student, $course);
            $this->complete($token, $course, $resources);
            $done[] = $enrollment;
        }
        // Matriculado pero sin terminar: no cumple los requisitos
        ['enrollment' => $unfinished] = $this->enroll($this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'caro@acme.com']), $course);

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Excel para finanzas', 'language' => 'es', 'duration_hours' => 24, 'issues_certificate' => true])->assertOk();

        $this->withToken($this->admin)->postJson($this->api('/certificates/issue-many'), ['enrollment_ids' => [...$done, $unfinished]])
            ->assertCreated()
            ->assertJsonPath('data.issued', 2)
            ->assertJsonPath('data.skipped', 1);

        $this->withToken($this->admin)->getJson($this->api('/certificates'))
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.counts.ISSUED', 2)
            ->assertJsonPath('meta.counts.REVOKED', 0);
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

    public function test_the_organization_designs_its_own_certificate_and_previews_it(): void
    {
        $template = $this->api('/certificates/template');

        // Sin tocar nada vale el diseño del sistema
        $this->withToken($this->admin)->getJson($template)
            ->assertOk()
            ->assertJsonPath('data.has_background', false)
            ->assertJsonPath('data.design.text_align', 'CENTER')
            ->assertJsonPath('data.design.qr_position', 'RIGHT')
            ->assertJsonPath('meta.options.positions', ['LEFT', 'CENTER', 'RIGHT']);

        $image = imagecreatetruecolor(297, 210);
        $path = tempnam(sys_get_temp_dir(), 'fondo').'.png';
        imagepng($image, $path);
        $this->withToken($this->admin)->post($template.'/background', ['file' => new UploadedFile($path, 'fondo.png', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_background', true);

        // Solo se envía lo que cambia; el resto conserva su valor
        $this->withToken($this->admin)->putJson($template, [
            'signer_name' => 'María Pérez', 'signer_title' => 'Directora',
            'design' => ['text_align' => 'LEFT', 'name_size' => 'L', 'primary_color' => '#123ABC', 'show_title' => false, 'signature_position' => 'CENTER'],
        ])
            ->assertOk()
            ->assertJsonPath('data.design.text_align', 'LEFT')
            ->assertJsonPath('data.design.primary_color', '#123ABC')
            ->assertJsonPath('data.design.show_title', false)
            ->assertJsonPath('data.design.show_logo', true)
            ->assertJsonPath('data.design.text_top', 'TOP');

        // La firma y el QR no pueden ir en el mismo sitio; un color debe ser #RRGGBB
        $this->withToken($this->admin)->putJson($template, ['design' => ['qr_position' => 'CENTER']])
            ->assertStatus(422)->assertJsonStructure(['details' => ['design.qr_position']]);
        $this->withToken($this->admin)->putJson($template, ['design' => ['accent_color' => 'azul']])->assertStatus(422);
        $this->withToken($this->admin)->putJson($template, ['design' => ['text_align' => 'ARRIBA']])->assertStatus(422);

        // La vista previa es un PDF con el diseño guardado y datos de ejemplo
        $preview = $this->withToken($this->admin)->get($template.'/preview')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $preview->getContent());

        $student = $this->login('acme', $this->student()->email);
        $this->withToken($student)->get($template.'/preview', ['Accept' => 'application/json'])->assertForbidden();
        $this->withToken($student)->putJson($template, ['design' => ['name_size' => 'S']])->assertForbidden();

        // Un certificado real se genera con el diseño propio, y quitar el fondo vuelve al del sistema
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse();
        ['token' => $token] = $this->enroll($this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'beto@acme.com']), $course);
        $this->complete($token, $course, $resources);
        $id = $this->withToken($token)->getJson($this->api('/me/certificates'))->json('data.0.id');
        $this->assertStringStartsWith('%PDF', $this->withToken($token)->get($this->api("/me/certificates/{$id}/pdf"))->assertOk()->getContent());

        $this->withToken($this->admin)->deleteJson($template.'/background')->assertOk()->assertJsonPath('data.has_background', false);
    }

    public function test_the_organization_places_each_element_of_its_certificate_freely(): void
    {
        $template = $this->api('/certificates/template');
        $admin = fn () => $this->withToken($this->admin);

        // Sin diseño libre vale el de opciones; el editor recibe de dónde partir
        $start = $admin()->getJson($template)->assertOk()
            ->assertJsonPath('data.layout', null)
            ->assertJsonPath('meta.canvas.sheets.landscape', [297, 210])
            ->assertJsonPath('meta.canvas.fields.student_name', 'Nombre del alumno')
            ->json('meta.canvas.defaults.landscape');
        $this->assertContains('qr', array_column($start['elements'], 'type'));

        // Una imagen propia: un sello
        $path = tempnam(sys_get_temp_dir(), 'sello').'.png';
        imagepng(imagecreatetruecolor(80, 40), $path);
        $stamp = $admin()->post($template.'/images', ['file' => new UploadedFile($path, 'sello.png', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->assertSame('image/png', $admin()->get($template."/images/{$stamp}")->assertOk()->headers->get('Content-Type'));

        $layout = ['orientation' => 'portrait', 'elements' => [
            ['id' => 'nombre', 'type' => 'field', 'field' => 'student_name', 'x' => 20.26, 'y' => 90, 'w' => 170, 'font' => 'DEJAVU_SERIF', 'size' => 28, 'color' => '#AA0000', 'bold' => true, 'align' => 'center'],
            ['id' => 'libre', 'type' => 'text', 'text' => 'Otorgado con honores', 'x' => 20, 'y' => 120, 'w' => 170, 'size' => 14],
            ['id' => 'sello', 'type' => 'image', 'source' => 'file', 'file_id' => $stamp, 'x' => 150, 'y' => 230, 'w' => 40, 'h' => 30],
            ['id' => 'qr', 'type' => 'qr', 'x' => 20, 'y' => 240, 'w' => 28],
            ['id' => 'codigo', 'type' => 'field', 'field' => 'code', 'x' => 52, 'y' => 250, 'w' => 80, 'font' => 'COURIER', 'size' => 12, 'align' => 'left'],
            // Fuera de la hoja vertical: se trae al borde
            ['id' => 'fuera', 'type' => 'text', 'text' => 'Al borde', 'x' => 290, 'y' => 10, 'w' => 60, 'size' => 9],
        ]];

        // Lo que hay en pantalla se previsualiza sin guardarlo
        $this->assertStringStartsWith('%PDF', $admin()->postJson($template.'/preview', ['layout' => $layout])->assertOk()->getContent());
        $admin()->getJson($template)->assertJsonPath('data.layout', null);

        $admin()->putJson($template.'/layout', ['layout' => $layout])->assertOk()
            ->assertJsonPath('data.layout.orientation', 'portrait')
            ->assertJsonPath('data.layout.elements.0.x', 20.3)
            ->assertJsonPath('data.layout.elements.0.color', '#aa0000')
            ->assertJsonPath('data.layout.elements.1.font', 'HELVETICA')
            ->assertJsonPath('data.layout.elements.2.file_id', $stamp)
            ->assertJsonPath('data.layout.elements.5.x', 207);

        // Sin código o sin QR no se puede verificar: no se guarda
        $without = fn (string $id) => ['orientation' => 'portrait', 'elements' => array_values(array_filter($layout['elements'], fn (array $element) => $element['id'] !== $id))];
        $admin()->putJson($template.'/layout', ['layout' => $without('qr')])->assertStatus(422)->assertJsonStructure(['details' => ['layout.elements']]);
        $admin()->putJson($template.'/layout', ['layout' => $without('codigo')])->assertStatus(422);

        // Ni una imagen que no es del certificado, ni un dato o una tipografía que no existen
        $broken = fn (int $index, array $change) => ['orientation' => 'portrait', 'elements' => array_replace($layout['elements'], [$index => $change + $layout['elements'][$index]])];
        $admin()->putJson($template.'/layout', ['layout' => $broken(2, ['file_id' => '01900000-0000-7000-8000-000000000000'])])->assertStatus(422);
        $admin()->putJson($template.'/layout', ['layout' => $broken(0, ['field' => 'password'])])->assertStatus(422);
        $admin()->putJson($template.'/layout', ['layout' => $broken(0, ['font' => 'COMIC'])])->assertStatus(422);

        // Los certificados salen con el diseño libre, en la hoja vertical
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse();
        ['token' => $token] = $this->enroll($this->student(), $course);
        $this->complete($token, $course, $resources);
        $id = $this->withToken($token)->getJson($this->api('/me/certificates'))->json('data.0.id');
        $pdf = $this->withToken($token)->get($this->api("/me/certificates/{$id}/pdf"))->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('/MediaBox \[0(\.0+)? 0(\.0+)? 595\.\d+ 841\.\d+\]/', $pdf);

        // Quitar el diseño libre devuelve el de opciones
        $this->withToken($this->admin)->putJson($template.'/layout', ['layout' => null])->assertOk()->assertJsonPath('data.layout', null);

        // Un alumno no toca el diseño
        $this->withToken($token)->putJson($template.'/layout', ['layout' => $layout])->assertForbidden();
        $this->withToken($token)->get($template."/images/{$stamp}", ['Accept' => 'application/json'])->assertForbidden();
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
