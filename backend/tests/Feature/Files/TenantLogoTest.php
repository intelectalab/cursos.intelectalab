<?php

namespace Tests\Feature\Files;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantLogoTest extends TestCase
{
    use RefreshDatabase;

    // PNG real de 1×1: la validación mira el contenido, no la extensión
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
    }

    /**
     * Upload con archivo real en disco: el tipo se deduce del contenido, como
     * en producción (el fake de Laravel lo deduce del nombre).
     */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return $this->upload($name, base64_decode(self::PNG));
    }

    private function allFiles()
    {
        return app(TenantContext::class)->runAsSystem(fn () => File::query()->get());
    }

    public function test_logo_is_uploaded_served_publicly_and_audited(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');

        $this->getJson($this->tenantUrl('acme', '/tenant'))->assertJsonPath('data.logo_url', null);

        $url = $this->withToken($token)
            ->post($this->tenantUrl('acme', '/settings/logo'), ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.logo_url');

        $file = $this->allFiles()->sole();
        $this->assertSame("/api/v1/public-files/{$file->id}", $url);
        $this->assertSame($tenant->id, $file->tenant_id);
        $this->assertSame('image/png', $file->mime_type);
        $this->assertSame("tenants/{$tenant->id}/tenant_logo/{$file->id}.png", $file->path);
        Storage::disk(config('files.disk'))->assertExists($file->path);

        // Visible sin sesión: en el contexto público y en la configuración
        $this->app['auth']->forgetGuards();
        $this->getJson($this->tenantUrl('acme', '/tenant'))->assertJsonPath('data.logo_url', $url);
        $this->get("http://acme.localhost{$url}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withToken($token)->getJson($this->tenantUrl('acme', '/settings'))
            ->assertJsonPath('data.logo_url', $url)
            ->assertJsonPath('meta.logo.max_kb', 2048);

        $this->assertTrue(AuditEvent::query()->where('action', 'tenant_settings.logo_updated')->exists());
    }

    public function test_replacing_and_removing_the_logo_cleans_up_the_previous_file(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $endpoint = $this->tenantUrl('acme', '/settings/logo');
        $disk = Storage::disk(config('files.disk'));

        $this->withToken($token)->post($endpoint, ['file' => $this->png('uno.png')], ['Accept' => 'application/json'])->assertOk();
        $first = $this->allFiles()->sole();

        $this->withToken($token)->post($endpoint, ['file' => $this->png('dos.png')], ['Accept' => 'application/json'])->assertOk();
        $second = $this->allFiles()->sole();

        $this->assertNotSame($first->id, $second->id);
        $disk->assertMissing($first->path);
        $disk->assertExists($second->path);

        $this->withToken($token)->deleteJson($endpoint)->assertOk()->assertJsonPath('data.logo_url', null);

        $this->assertCount(0, $this->allFiles());
        $disk->assertMissing($second->path);
        $this->getJson($this->tenantUrl('acme', '/tenant'))->assertJsonPath('data.logo_url', null);
        $this->assertTrue(AuditEvent::query()->where('action', 'tenant_settings.logo_removed')->exists());
    }

    public function test_upload_is_validated_by_content_type_and_size(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $endpoint = $this->tenantUrl('acme', '/settings/logo');
        $headers = ['Accept' => 'application/json'];

        // Un script con nombre de imagen: se rechaza por contenido
        $disguised = $this->upload('logo.png', '<?php echo "hola";');
        $this->withToken($token)->post($endpoint, ['file' => $disguised], $headers)
            ->assertStatus(422)->assertJsonStructure(['details' => ['file']]);

        $svg = $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->withToken($token)->post($endpoint, ['file' => $svg], $headers)->assertStatus(422);

        $heavy = $this->upload('logo.png', base64_decode(self::PNG).str_repeat('0', 2049 * 1024));
        $this->withToken($token)->post($endpoint, ['file' => $heavy], $headers)->assertStatus(422);

        $this->withToken($token)->post($endpoint, [], $headers)->assertStatus(422);

        $this->assertCount(0, $this->allFiles());
    }

    public function test_logo_requires_permission(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $student = $this->createUser($tenant, RoleCatalog::STUDENT);
        $token = $this->login('acme', $student->email);
        $endpoint = $this->tenantUrl('acme', '/settings/logo');

        $this->post($endpoint, ['file' => $this->png()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->withToken($token)->post($endpoint, ['file' => $this->png()], ['Accept' => 'application/json'])->assertForbidden();
        $this->withToken($token)->deleteJson($endpoint)->assertForbidden();

        $this->assertCount(0, $this->allFiles());
    }

    public function test_files_are_isolated_between_tenants(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');

        $url = $this->withToken($this->login('alfa', 'admin@alfa.com'))
            ->post($this->tenantUrl('alfa', '/settings/logo'), ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.logo_url');

        $this->app['auth']->forgetGuards();

        // El logo de alfa no existe para beta ni para el dominio central
        $this->get("http://alfa.localhost{$url}")->assertOk();
        $this->getJson("http://beta.localhost{$url}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson("http://localhost{$url}")->assertNotFound();
        $this->getJson($this->tenantUrl('beta', '/tenant'))->assertJsonPath('data.logo_url', null);

        // El administrador de beta no puede borrar el logo de alfa
        $this->withToken($this->login('beta', 'admin@beta.com'))
            ->deleteJson($this->tenantUrl('beta', '/settings/logo'))->assertOk();
        $this->assertSame($alfa->id, $this->allFiles()->sole()->tenant_id);
    }

    public function test_private_files_are_never_served_by_the_public_route(): void
    {
        config(['files.purposes.private_sample' => [
            'visibility' => 'private',
            'limit' => 'document',
            'extensions' => ['png'],
        ]]);

        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $file = app(TenantContext::class)->runForTenant(
            $tenant->id,
            fn () => app(FileStorage::class)->store($this->png('contrato.png'), 'private_sample')
        );

        $this->assertFalse($file->isPublic());
        $this->getJson("http://acme.localhost/api/v1/public-files/{$file->id}")->assertNotFound();
    }

    public function test_only_declared_purposes_are_accepted(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');

        $this->expectException(\InvalidArgumentException::class);

        app(TenantContext::class)->runForTenant(
            $tenant->id,
            fn () => app(FileStorage::class)->store($this->png(), 'cualquier_cosa')
        );
    }
}
