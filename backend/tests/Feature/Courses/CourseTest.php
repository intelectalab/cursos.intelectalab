<?php

namespace Tests\Feature\Courses;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    private function url(string $path = ''): string
    {
        return $this->tenantUrl('acme', "/courses{$path}");
    }

    /**
     * Upload con archivo real: el tipo se deduce del contenido, como en producción.
     */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function createCourse(array $overrides = []): string
    {
        return $this->withToken($this->admin)
            ->postJson($this->url(), $overrides + ['title' => 'Excel para finanzas', 'language' => 'es'])
            ->assertCreated()
            ->json('data.id');
    }

    private function addSection(string $course, string $title = 'Introducción'): string
    {
        return $this->withToken($this->admin)
            ->postJson($this->url("/{$course}/sections"), ['title' => $title])
            ->assertCreated()
            ->json('data.id');
    }

    private function addText(string $course, string $section, string $title = 'Bienvenida'): string
    {
        return $this->withToken($this->admin)
            ->postJson($this->url("/{$course}/sections/{$section}/resources"), [
                'type' => 'TEXT', 'title' => $title, 'body' => 'Contenido de la lección.',
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function publishableCourse(): string
    {
        $course = $this->createCourse();
        $this->addText($course, $this->addSection($course));

        return $course;
    }

    /**
     * Usuario con un rol propio de la organización y su token.
     *
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions, string $roleName = 'Rol de prueba'): array
    {
        $roleId = $this->withToken($this->admin)
            ->postJson($this->tenantUrl('acme', '/roles'), ['name' => $roleName, 'permissions' => $permissions])
            ->assertCreated()
            ->json('data.id');

        $user = app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($roleId) {
            $user = User::factory()->create(['password' => self::PASSWORD]);
            $user->roles()->attach($roleId);

            return $user;
        });

        return [$user, $this->login('acme', $user->email)];
    }

    private function files()
    {
        return app(TenantContext::class)->runAsSystem(fn () => File::query()->get());
    }

    public function test_course_is_created_as_draft_edited_and_listed(): void
    {
        $id = $this->withToken($this->admin)->postJson($this->url(), [
            'title' => 'Excel para finanzas',
            'summary' => 'De cero a tablas dinámicas.',
            'category' => 'Ofimática',
            'level' => 'BASIC',
            'duration_hours' => 24,
            'language' => 'es',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', null)
            ->assertJsonPath('data.pending_changes', false)
            ->json('data.id');

        $this->withToken($this->admin)->putJson($this->url("/{$id}"), [
            'title' => 'Excel financiero', 'category' => 'Ofimática', 'language' => 'es',
        ])->assertOk()->assertJsonPath('data.title', 'Excel financiero')->assertJsonPath('data.summary', null);

        $this->createCourse(['title' => 'Oratoria', 'category' => 'Habilidades']);

        $this->withToken($this->admin)->getJson($this->url('?search=excel'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Excel financiero');
        $this->withToken($this->admin)->getJson($this->url('?status=PUBLISHED'))->assertJsonPath('meta.total', 0);
        $this->withToken($this->admin)->getJson($this->url('/options'))
            ->assertOk()
            ->assertJsonPath('data.categories', ['Habilidades', 'Ofimática'])
            ->assertJsonPath('data.resource_types', ['VIDEO', 'DOCUMENT', 'LINK', 'TEXT']);

        $this->assertSame(
            ['courses.created', 'courses.updated', 'courses.created'],
            AuditEvent::query()->where('action', 'like', 'courses.%')->orderBy('created_at')->orderBy('id')->pluck('action')->all()
        );
    }

    public function test_course_data_is_validated(): void
    {
        $this->withToken($this->admin)->postJson($this->url(), [
            'title' => '', 'level' => 'EXPERTO', 'duration_hours' => 0, 'language' => 'xx',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['title', 'level', 'duration_hours', 'language']]);
    }

    public function test_sections_and_resources_are_managed_and_reordered(): void
    {
        $course = $this->createCourse();
        $intro = $this->addSection($course, 'Introducción');
        $cierre = $this->addSection($course, 'Cierre');

        $text = $this->addText($course, $intro, 'Bienvenida');
        $link = $this->withToken($this->admin)->postJson($this->url("/{$course}/sections/{$intro}/resources"), [
            'type' => 'LINK', 'title' => 'Plantilla', 'url' => 'https://example.com/plantilla',
        ])->assertCreated()->assertJsonPath('data.body', null)->json('data.id');

        $this->withToken($this->admin)->putJson($this->url("/{$course}/sections/{$cierre}"), ['title' => 'Cierre y evaluación'])->assertOk();
        $this->withToken($this->admin)->putJson($this->url("/{$course}/sections/order"), ['ids' => [$cierre, $intro]])->assertOk();
        $this->withToken($this->admin)->putJson($this->url("/{$course}/sections/{$intro}/resources/order"), ['ids' => [$link, $text]])->assertOk();
        $this->withToken($this->admin)->post($this->url("/{$course}/resources/{$text}"), ['title' => 'Bienvenida al curso', 'body' => 'Nuevo texto.'], self::JSON)
            ->assertOk()->assertJsonPath('data.title', 'Bienvenida al curso');

        $this->withToken($this->admin)->getJson($this->url("/{$course}"))
            ->assertOk()
            ->assertJsonPath('data.sections.0.title', 'Cierre y evaluación')
            ->assertJsonPath('data.sections.1.resources.0.title', 'Plantilla')
            ->assertJsonPath('data.sections.1.resources.1.title', 'Bienvenida al curso')
            ->assertJsonPath('data.resources_count', 2);

        // El orden debe traer exactamente los elementos actuales
        $this->withToken($this->admin)->putJson($this->url("/{$course}/sections/order"), ['ids' => [$intro]])->assertStatus(422);

        $this->withToken($this->admin)->deleteJson($this->url("/{$course}/resources/{$link}"))->assertOk();
        $this->withToken($this->admin)->deleteJson($this->url("/{$course}/sections/{$intro}"))->assertOk();
        $this->withToken($this->admin)->getJson($this->url("/{$course}"))
            ->assertJsonCount(1, 'data.sections')
            ->assertJsonPath('data.resources_count', 0);
    }

    public function test_resources_are_validated_by_type(): void
    {
        $course = $this->createCourse();
        $section = $this->addSection($course);
        $endpoint = $this->url("/{$course}/sections/{$section}/resources");

        $this->withToken($this->admin)->postJson($endpoint, ['type' => 'AUDIO', 'title' => 'Clase 1'])->assertStatus(422);
        $this->withToken($this->admin)->postJson($endpoint, ['type' => 'LINK', 'title' => 'Sitio', 'url' => 'javascript:alert(1)'])->assertStatus(422);
        $this->withToken($this->admin)->postJson($endpoint, ['type' => 'TEXT', 'title' => 'Lección'])->assertStatus(422);
        $this->withToken($this->admin)->postJson($endpoint, ['type' => 'DOCUMENT', 'title' => 'Guía'])->assertStatus(422);
        $this->withToken($this->admin)
            ->post($endpoint, ['type' => 'DOCUMENT', 'title' => 'Guía', 'file' => $this->upload('guia.pdf', '<?php echo 1;')], self::JSON)
            ->assertStatus(422);

        $this->assertCount(0, $this->files());
    }

    public function test_video_resources_accept_only_youtube_links(): void
    {
        $course = $this->createCourse();
        $section = $this->addSection($course);
        $endpoint = $this->url("/{$course}/sections/{$section}/resources");
        $canonical = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

        // Cualquier formato de enlace de YouTube se guarda con la misma dirección
        $accepted = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://m.youtube.com/shorts/dQw4w9WgXcQ',
        ];
        foreach ($accepted as $url) {
            $this->withToken($this->admin)->postJson($endpoint, ['type' => 'VIDEO', 'title' => 'Clase 1', 'url' => $url])
                ->assertCreated()
                ->assertJsonPath('data.url', $canonical)
                ->assertJsonPath('data.video_id', 'dQw4w9WgXcQ');
        }

        $rejected = [
            null,
            'https://vimeo.com/123456789',
            'https://www.youtube.com/@canal',
            'https://www.youtube.com/watch?v=corto',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'javascript:alert(1)//youtu.be/dQw4w9WgXcQ',
        ];
        foreach ($rejected as $url) {
            $this->withToken($this->admin)->postJson($endpoint, ['type' => 'VIDEO', 'title' => 'Clase 1', 'url' => $url])
                ->assertStatus(422)
                ->assertJsonStructure(['details' => ['url']]);
        }

        // Al editar se valida igual, y el video viaja en la versión publicada
        $resource = $this->withToken($this->admin)->getJson($this->url("/{$course}"))->json('data.sections.0.resources.0.id');
        $this->withToken($this->admin)->post($this->url("/{$course}/resources/{$resource}"), ['title' => 'Clase 1', 'url' => 'https://example.com'], self::JSON)
            ->assertStatus(422);
        $this->withToken($this->admin)->post($this->url("/{$course}/resources/{$resource}"), ['title' => 'Intro', 'url' => 'https://youtu.be/9bZkp7q19f0'], self::JSON)
            ->assertOk()->assertJsonPath('data.video_id', '9bZkp7q19f0');

        $version = $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"))->assertOk()->json('data.versions.0.id');
        $this->withToken($this->admin)->getJson($this->url("/{$course}/versions/{$version}"))
            ->assertJsonPath('data.snapshot.sections.0.resources.0.url', 'https://www.youtube.com/watch?v=9bZkp7q19f0');
    }

    public function test_material_is_private_and_downloaded_only_with_access(): void
    {
        $course = $this->createCourse();
        $section = $this->addSection($course);

        $resource = $this->withToken($this->admin)
            ->post($this->url("/{$course}/sections/{$section}/resources"), [
                'type' => 'DOCUMENT', 'title' => 'Guía del curso', 'file' => $this->upload('guia.pdf', self::PDF),
            ], self::JSON)
            ->assertCreated()
            ->assertJsonPath('data.file.name', 'guia.pdf')
            ->assertJsonPath('data.file.mime_type', 'application/pdf')
            ->json('data.id');

        $file = $this->files()->sole();
        $this->assertFalse($file->isPublic());
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/course_material/", $file->path);

        $download = $this->url("/{$course}/resources/{$resource}/download");
        $this->withToken($this->admin)->get($download)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename=guia.pdf');

        // Sin sesión, por la ruta pública o con un alumno: no se entrega
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson($download)->assertUnauthorized();
        $this->getJson("http://acme.localhost/api/v1/public-files/{$file->id}")->assertNotFound();
        $student = $this->createUser($this->tenant, RoleCatalog::STUDENT);
        $this->withToken($this->login('acme', $student->email))->getJson($download)->assertForbidden();

        // Reemplazar el archivo borra el anterior; eliminar el recurso borra el nuevo
        $this->withToken($this->admin)
            ->post($this->url("/{$course}/resources/{$resource}"), ['title' => 'Guía v2', 'file' => $this->upload('guia-v2.pdf', self::PDF)], self::JSON)
            ->assertOk()->assertJsonPath('data.file.name', 'guia-v2.pdf');
        Storage::disk(config('files.disk'))->assertMissing($file->path);
        $this->assertCount(1, $this->files());

        $this->withToken($this->admin)->deleteJson($this->url("/{$course}/resources/{$resource}"))->assertOk();
        $this->assertCount(0, $this->files());
    }

    public function test_publishing_requires_content_and_creates_numbered_versions(): void
    {
        $course = $this->createCourse();

        $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"))
            ->assertStatus(422)->assertJsonPath('code', 'COURSE_NOT_PUBLISHABLE');

        $section = $this->addSection($course);
        $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"))->assertStatus(422);

        $this->addText($course, $section, 'Bienvenida');
        $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"), ['note' => 'Lanzamiento'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.pending_changes', false)
            ->assertJsonPath('data.versions.0.note', 'Lanzamiento')
            ->assertJsonPath('data.versions.0.published_by', 'Ada Admin');

        // Sin cambios no hay nada que publicar
        $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"))
            ->assertStatus(422)->assertJsonPath('code', 'COURSE_NOT_PUBLISHABLE');

        // Editar deja cambios pendientes; la versión publicada no se altera
        $this->addText($course, $section, 'Segunda lección');
        $detail = $this->withToken($this->admin)->getJson($this->url("/{$course}"))
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.pending_changes', true)
            ->assertJsonPath('data.version', 1)
            ->json('data');

        $this->withToken($this->admin)->getJson($this->url("/{$course}/versions/{$detail['versions'][0]['id']}"))
            ->assertOk()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.snapshot.title', 'Excel para finanzas')
            ->assertJsonCount(1, 'data.snapshot.sections.0.resources');

        $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"))
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.versions.0.resources_count', 2)
            ->assertJsonPath('data.versions.1.resources_count', 1);

        $this->assertSame(2, AuditEvent::query()->where('action', 'courses.published')->count());
    }

    public function test_files_used_by_a_published_version_are_kept(): void
    {
        $course = $this->createCourse();
        $section = $this->addSection($course);
        $resource = $this->withToken($this->admin)
            ->post($this->url("/{$course}/sections/{$section}/resources"), [
                'type' => 'DOCUMENT', 'title' => 'Guía', 'file' => $this->upload('guia.pdf', self::PDF),
            ], self::JSON)->json('data.id');
        $this->withToken($this->admin)
            ->post($this->url("/{$course}/cover"), ['file' => $this->upload('portada.png', base64_decode(self::PNG))], self::JSON)
            ->assertOk();

        $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"))->assertOk();

        // El borrador los suelta, pero la versión 1 todavía los necesita
        $this->withToken($this->admin)->deleteJson($this->url("/{$course}/resources/{$resource}"))->assertOk();
        $this->withToken($this->admin)->deleteJson($this->url("/{$course}/cover"))->assertOk()->assertJsonPath('data.cover_url', null);

        $this->assertCount(2, $this->files());
        $this->files()->each(fn (File $file) => Storage::disk(config('files.disk'))->assertExists($file->path));
    }

    public function test_cover_is_public_and_replaced(): void
    {
        $course = $this->createCourse();

        $first = $this->withToken($this->admin)
            ->post($this->url("/{$course}/cover"), ['file' => $this->upload('portada.png', base64_decode(self::PNG))], self::JSON)
            ->assertOk()->json('data.cover_url');
        $second = $this->withToken($this->admin)
            ->post($this->url("/{$course}/cover"), ['file' => $this->upload('otra.png', base64_decode(self::PNG))], self::JSON)
            ->assertOk()->json('data.cover_url');

        $this->assertNotSame($first, $second);
        $this->assertCount(1, $this->files());
        $this->withToken($this->admin)->getJson($this->url("/{$course}"))->assertJsonPath('data.cover_url', $second);

        $this->app['auth']->forgetGuards();
        $this->get("http://acme.localhost{$second}")->assertOk();
        $this->getJson("http://acme.localhost{$first}")->assertNotFound();
    }

    public function test_archived_course_cannot_be_modified_until_restored(): void
    {
        $course = $this->publishableCourse();
        $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"))->assertOk();

        $this->withToken($this->admin)->postJson($this->url("/{$course}/archive"))->assertOk()->assertJsonPath('data.status', 'ARCHIVED');

        $this->withToken($this->admin)->postJson($this->url("/{$course}/sections"), ['title' => 'Extra'])
            ->assertStatus(409)->assertJsonPath('code', 'COURSE_INVALID_STATE');
        $this->withToken($this->admin)->putJson($this->url("/{$course}"), ['title' => 'Otro', 'language' => 'es'])->assertStatus(409);
        $this->withToken($this->admin)->postJson($this->url("/{$course}/publish"))->assertStatus(409);
        $this->withToken($this->admin)->postJson($this->url("/{$course}/archive"))->assertStatus(409);

        // Vuelve a publicado porque ya tenía una versión
        $this->withToken($this->admin)->postJson($this->url("/{$course}/restore"))->assertOk()->assertJsonPath('data.status', 'PUBLISHED');
        $this->withToken($this->admin)->postJson($this->url("/{$course}/restore"))->assertStatus(409);

        $draft = $this->createCourse(['title' => 'Borrador']);
        $this->withToken($this->admin)->postJson($this->url("/{$draft}/archive"))->assertOk();
        $this->withToken($this->admin)->postJson($this->url("/{$draft}/restore"))->assertOk()->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_editing_and_publishing_are_separate_permissions(): void
    {
        $course = $this->publishableCourse();
        [, $editor] = $this->userWith(['courses.read', 'courses.create', 'courses.update', 'courses.content.write'], 'Editor');
        [, $publisher] = $this->userWith(['courses.read', 'courses.publish'], 'Publicador');
        $student = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::STUDENT)->email);

        $this->withToken($editor)->postJson($this->url("/{$course}/sections"), ['title' => 'Nueva'])->assertCreated();
        $this->withToken($editor)->putJson($this->url("/{$course}"), ['title' => 'Editado', 'language' => 'es'])->assertOk();
        $this->withToken($editor)->postJson($this->url("/{$course}/publish"))
            ->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->withToken($editor)->postJson($this->url("/{$course}/archive"))->assertForbidden();

        $this->withToken($publisher)->postJson($this->url("/{$course}/publish"))->assertOk();
        $this->withToken($publisher)->putJson($this->url("/{$course}"), ['title' => 'No puede', 'language' => 'es'])->assertForbidden();
        $this->withToken($publisher)->postJson($this->url("/{$course}/sections"), ['title' => 'No puede'])->assertForbidden();
        $this->withToken($publisher)->postJson($this->url(), ['title' => 'No puede', 'language' => 'es'])->assertForbidden();

        foreach ([['getJson', ''], ['getJson', "/{$course}"], ['postJson', '']] as [$method, $path]) {
            $this->withToken($student)->{$method}($this->url($path))->assertForbidden();
        }
    }

    public function test_teacher_only_sees_and_edits_assigned_courses(): void
    {
        $mine = $this->publishableCourse();
        $other = $this->createCourse(['title' => 'Curso ajeno']);
        $otherSection = $this->addSection($other);
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Tomás', 'last_name' => 'Docente']);
        $student = $this->createUser($this->tenant, RoleCatalog::STUDENT);
        $token = $this->login('acme', $teacher->email);

        // Solo quienes pueden dictar aparecen como candidatos
        $candidates = $this->withToken($this->admin)->getJson($this->url('/options'))->json('data.teachers.*.id');
        $this->assertContains($teacher->id, $candidates);
        $this->assertNotContains($student->id, $candidates);

        $this->withToken($token)->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($token)->getJson($this->url("/{$mine}"))->assertNotFound();

        $this->withToken($this->admin)->putJson($this->url("/{$mine}/teachers"), ['user_ids' => [$teacher->id]])
            ->assertOk()->assertJsonPath('data.teachers.0.name', 'Tomás Docente');

        $this->withToken($token)->getJson($this->url())->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine);
        $this->withToken($token)->getJson($this->url("/{$mine}"))->assertOk()->assertJsonPath('meta.can_edit_content', true);
        $this->withToken($token)->postJson($this->url("/{$mine}/sections"), ['title' => 'Del profesor'])->assertCreated();
        $this->withToken($token)->getJson($this->url('/options'))->assertOk()->assertJsonMissingPath('data.teachers');

        // Nada sobre el curso ajeno, ni datos generales ni publicación del propio
        $this->withToken($token)->getJson($this->url("/{$other}"))->assertNotFound();
        $this->withToken($token)->postJson($this->url("/{$other}/sections"), ['title' => 'Intruso'])->assertNotFound();
        $this->withToken($token)->postJson($this->url("/{$other}/sections/{$otherSection}/resources"), [
            'type' => 'TEXT', 'title' => 'Intruso', 'body' => 'x',
        ])->assertNotFound();
        $this->withToken($token)->putJson($this->url("/{$mine}"), ['title' => 'No puede', 'language' => 'es'])->assertForbidden();
        $this->withToken($token)->postJson($this->url("/{$mine}/publish"))->assertForbidden();

        // Al quitarlo pierde el acceso
        $this->withToken($this->admin)->putJson($this->url("/{$mine}/teachers"), ['user_ids' => []])->assertOk();
        $this->withToken($token)->getJson($this->url("/{$mine}"))->assertNotFound();
        $this->assertSame(2, AuditEvent::query()->where('action', 'courses.teachers_updated')->count());
    }

    public function test_courses_are_isolated_between_tenants(): void
    {
        $course = $this->publishableCourse();
        $section = $this->withToken($this->admin)->getJson($this->url("/{$course}"))->json('data.sections.0.id');

        $beta = $this->provisionTenant('beta', 'admin@beta.com');
        $betaUser = $this->createUser($beta, RoleCatalog::TEACHER);
        $token = $this->login('beta', 'admin@beta.com');
        $betaUrl = fn (string $path = '') => $this->tenantUrl('beta', "/courses{$path}");

        $this->withToken($token)->getJson($betaUrl())->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($token)->getJson($betaUrl("/{$course}"))->assertNotFound();
        $this->withToken($token)->putJson($betaUrl("/{$course}"), ['title' => 'Robado', 'language' => 'es'])->assertNotFound();
        $this->withToken($token)->postJson($betaUrl("/{$course}/publish"))->assertNotFound();
        $this->withToken($token)->postJson($betaUrl("/{$course}/sections/{$section}/resources"), [
            'type' => 'TEXT', 'title' => 'Intruso', 'body' => 'x',
        ])->assertNotFound();

        // Tampoco se puede asignar como profesor a un usuario de otra organización
        $this->withToken($this->admin)->putJson($this->url("/{$course}/teachers"), ['user_ids' => [$betaUser->id]])->assertStatus(422);

        $context = app(TenantContext::class);
        $this->assertSame(0, $context->runForTenant($beta->id, fn () => Course::query()->count()));
        $this->assertSame(1, $context->runForTenant($this->tenant->id, fn () => Course::query()->count()));
    }
}
