<?php

namespace Tests\Feature\Academic;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * SCORM (RF-SCORM-001): el personal sube el paquete a una actividad; el
 * alumno lo abre en el aula, el paquete guarda su avance y su nota, y eso
 * cuenta para el avance y la nota final del curso.
 */
class ScormTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('files.disk'));
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com', 'first_name' => 'Ana', 'last_name' => 'Torres']);
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * Un .zip con su manifiesto; `$files` reemplaza o agrega archivos.
     *
     * @param  array<string, string>  $files
     */
    private function package(array $files = [], bool $withManifest = true): UploadedFile
    {
        $manifest = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <manifest identifier="curso" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2">
              <metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata>
              <organizations default="org1">
                <organization identifier="org1">
                  <title>Excel interactivo</title>
                  <item identifier="item1" identifierref="res1"><title>Lección 1</title><adlcp:masteryscore>80</adlcp:masteryscore></item>
                </organization>
              </organizations>
              <resources>
                <resource identifier="res1" type="webcontent" adlcp:scormtype="sco" href="lecciones/index.html"><file href="lecciones/index.html"/></resource>
              </resources>
            </manifest>
            XML;

        $path = tempnam(sys_get_temp_dir(), 'scorm').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (($withManifest ? ['imsmanifest.xml' => $manifest] : []) + $files + ['lecciones/index.html' => '<html><body>Hola</body></html>', 'lecciones/app.js' => 'console.log(1)'] as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'excel.zip', 'application/zip', null, true);
    }

    /**
     * @return array{0: string, 1: string} curso y actividad SCORM
     */
    private function course(): array
    {
        $course = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es'])->assertCreated()->json('data.id');
        $section = $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $activity = $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/sections/{$section}/activities"), ['type' => 'SCORM', 'title' => 'Excel interactivo', 'weight' => 1])
            ->assertCreated()->assertJsonPath('data.package', null)->json('data.id');

        return [$course, $activity];
    }

    public function test_the_package_is_checked_on_upload_and_needed_to_publish(): void
    {
        [$course, $activity] = $this->course();
        $upload = fn (UploadedFile $file) => $this->withToken($this->admin)->post($this->api("/courses/{$course}/activities/{$activity}/scorm"), ['file' => $file], ['Accept' => 'application/json']);

        // Sin paquete no se publica
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/publish"))->assertStatus(422);

        $upload($this->package(withManifest: false))->assertStatus(422)->assertJsonPath('details.file.0', 'No es un paquete SCORM: falta el archivo imsmanifest.xml en la raíz del .zip.');
        $upload($this->package(['../fuera.txt' => 'x']))->assertStatus(422);

        $upload($this->package())->assertCreated()
            ->assertJsonPath('data.package.version', '1.2')
            ->assertJsonPath('data.package.title', 'Excel interactivo')
            ->assertJsonPath('data.package.mastery_score', 80);

        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        // El personal lo prueba sin guardar nada
        $preview = $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/activities/{$activity}/scorm/preview"))->assertOk()->json('data');
        $this->assertFalse($preview['tracking']);
        $this->assertStringEndsWith('/lecciones/index.html', $preview['launch_url']);
    }

    public function test_the_student_opens_it_resumes_it_and_its_score_counts(): void
    {
        [$course, $activity] = $this->course();
        $this->withToken($this->admin)->post($this->api("/courses/{$course}/activities/{$activity}/scorm"), ['file' => $this->package()], ['Accept' => 'application/json'])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/publish"))->assertOk();
        $ana = $this->createUserId('ana@acme.com');
        $enrollment = $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$ana]])->assertCreated()->json('data.0.id');

        $student = $this->flushHeaders()->login('acme', 'ana@acme.com');
        $launch = $this->withToken($student)->getJson($this->api("/me/courses/{$course}/activities/{$activity}/scorm"))->assertOk()->json('data');
        $this->assertSame('Torres, Ana', $launch['cmi']['cmi.core.student_name']);
        $this->assertSame('ab-initio', $launch['cmi']['cmi.core.entry']);

        // Los archivos se ven solo con el enlace firmado, y dentro del aula
        $this->flushHeaders();
        $page = $this->get('http://acme.localhost'.$launch['launch_url'])->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString('Hola', $page->streamedContent());
        $this->get('http://acme.localhost'.str_replace('lecciones/index.html', 'lecciones/app.js', $launch['launch_url']))->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->get('http://acme.localhost'.preg_replace('/\.[a-f0-9]{40}\//', '.'.str_repeat('0', 40).'/', $launch['launch_url']))->assertNotFound();
        $this->get('http://acme.localhost'.str_replace('lecciones/index.html', '..%2F..%2F.env', $launch['launch_url']))->assertNotFound();

        // Avanza a medias y retoma donde quedó
        $commit = fn (array $cmi) => $this->withToken($student)->postJson($this->api("/me/courses/{$course}/activities/{$activity}/scorm"), ['cmi' => $cmi]);
        $commit(['cmi.core.lesson_status' => 'incomplete', 'cmi.suspend_data' => 'pagina=3', 'cmi.core.student_name' => 'Otra'])->assertOk()->assertJsonPath('data.completed', false);
        $cmi = $this->withToken($student)->getJson($this->api("/me/courses/{$course}/activities/{$activity}/scorm"))->json('data.cmi');
        $this->assertSame(['pagina=3', 'resume', 'Torres, Ana'], [$cmi['cmi.suspend_data'], $cmi['cmi.core.entry'], $cmi['cmi.core.student_name']]);

        // Termina con 90 de 100: supera el 80 del paquete y queda aprobado
        $commit(['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => '90', 'cmi.core.score.max' => '100'])->assertOk()
            ->assertJsonPath('data.lesson_status', 'passed')
            ->assertJsonPath('data.score_percent', 90)
            ->assertJsonPath('data.completed', true);

        $this->withToken($student)->getJson($this->api("/me/courses/{$course}/grades"))->assertOk()->assertJsonPath('data.final_percent', 90);
        $this->withToken($student)->getJson($this->api("/me/courses/{$course}"))->assertOk()->assertJsonPath('data.progress.percent', 100);

        // El personal lo reinicia: vuelve a empezar
        $this->flushHeaders()->withToken($this->admin)->deleteJson($this->api("/courses/{$course}/enrollments/{$enrollment}/activities/{$activity}/scorm"))->assertOk();
        $again = $this->flushHeaders()->withToken($student)->getJson($this->api("/me/courses/{$course}/activities/{$activity}/scorm"))->assertJsonPath('data.state.lesson_status', 'not attempted')->json('data.cmi');
        $this->assertSame('ab-initio', $again['cmi.core.entry']);
    }

    private function createUserId(string $email): string
    {
        return $this->withToken($this->admin)->getJson($this->api('/users?search='.urlencode($email)))->json('data.0.id');
    }
}
