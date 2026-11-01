<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Aplicación instalable: cada academia se instala con su nombre, su color y
 * su ícono (armado con el logo o subido por ella), sin iniciar sesión.
 */
class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        $this->provisionTenant('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function upload(string $path, string $name, int $width = 300, int $height = 120): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 200, 10));
        $file = tempnam(sys_get_temp_dir(), 'img');
        imagepng($image, $file);

        return new UploadedFile($file, $name, 'image/png', null, true);
    }

    public function test_the_manifest_carries_the_academy_name_color_and_address_without_a_session(): void
    {
        $this->withToken($this->login('acme', 'admin@acme.com'))->putJson($this->api('/settings/appearance'), ['primary' => '#123456', 'accent' => '#0ea5e9'])->assertOk();
        $this->flushHeaders();

        $response = $this->get($this->api('/app/manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');

        $this->assertSame('/', $response->json('start_url'));
        $this->assertSame('/', $response->json('scope'));
        $this->assertSame('standalone', $response->json('display'));
        $this->assertSame('#123456', $response->json('theme_color'));
        $this->assertCount(3, $response->json('icons'));
        $this->assertSame('maskable', $response->json('icons.2.purpose'));

        // Fuera de una academia (la portada de la plataforma) no hay aplicación que instalar
        $this->get($this->centralUrl('/app/manifest'))->assertNotFound();
    }

    public function test_in_path_mode_each_academy_installs_under_its_own_address(): void
    {
        config(['tenancy.mode' => 'path', 'tenancy.central_domain' => 'cursos.test', 'tenancy.public_url' => null, 'app.url' => 'https://cursos.test']);

        $response = $this->get('https://cursos.test/api/v1/app/manifest?tenant=acme')->assertOk();

        $this->assertSame('/a/acme/', $response->json('start_url'));
        $this->assertSame('/a/acme/', $response->json('scope'));
        $this->assertStringContainsString('tenant=acme', $response->json('icons.0.src'));
    }

    public function test_the_icon_is_built_from_the_logo_and_changes_its_address_when_the_logo_changes(): void
    {
        $before = $this->get($this->api('/app/manifest'))->json('icons.0.src');

        // Sin logo: las iniciales sobre el color de la academia
        $icon = $this->get($this->api('/app/icon/192'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([192, 192], array_slice(getimagesizefromstring($icon->getContent()), 0, 2));

        $this->withToken($this->login('acme', 'admin@acme.com'))->post($this->api('/settings/logo'), ['file' => $this->upload('logo', 'logo.png')], ['Accept' => 'application/json'])->assertOk();
        $this->flushHeaders();

        $this->assertNotSame($before, $this->get($this->api('/app/manifest'))->json('icons.0.src'));
        $this->assertSame([512, 512], array_slice(getimagesizefromstring($this->get($this->api('/app/icon/512/maskable'))->getContent()), 0, 2));

        // Solo los tamaños que pide el estándar
        $this->get($this->api('/app/icon/64'))->assertNotFound();
    }

    public function test_the_admin_uploads_an_own_icon_and_removes_it(): void
    {
        $token = $this->login('acme', 'admin@acme.com');

        $this->withToken($token)->post($this->api('/settings/appearance/app-icon'), ['file' => $this->upload('icono', 'icono.png', 600, 600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.custom', true);
        $this->withToken($token)->getJson($this->api('/settings/appearance'))->assertJsonPath('meta.app_icon.custom', true);

        // El ícono propio cubre todo el cuadrado: la esquina ya no es el color de la academia, es la del ícono
        $this->flushHeaders();
        $image = imagecreatefromstring($this->get($this->api('/app/icon/192'))->getContent());
        $this->assertSame(['red' => 250, 'green' => 200, 'blue' => 10], array_slice(imagecolorsforindex($image, imagecolorat($image, 0, 0)), 0, 3));

        $this->withToken($token)->deleteJson($this->api('/settings/appearance/app-icon'))->assertOk()->assertJsonPath('data.custom', false);
        $this->withToken($token)->getJson($this->api('/settings/appearance'))->assertJsonPath('meta.app_icon.custom', false);
    }

    public function test_only_who_edits_the_appearance_changes_the_icon_and_only_with_an_image(): void
    {
        $tenant = $this->provisionTenant('beta', 'admin@beta.com');
        $this->createUser($tenant, RoleCatalog::STUDENT, ['email' => 'ana@beta.com']);

        $this->withToken($this->login('beta', 'ana@beta.com'))->post($this->tenantUrl('beta', '/settings/appearance/app-icon'), ['file' => $this->upload('icono', 'icono.png')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->flushHeaders();
        $this->withToken($this->login('beta', 'admin@beta.com'))->post($this->tenantUrl('beta', '/settings/appearance/app-icon'), ['file' => UploadedFile::fake()->create('icono.php', 10, 'text/x-php')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonStructure(['details' => ['file']]);
    }
}
