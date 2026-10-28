<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\BrandColor;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Apariencia de cada academia: sus colores (con el principal siempre
 * legible), su pantalla de acceso y los correos con su color.
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function admin(): string
    {
        $this->flushHeaders();

        return $this->login('acme', 'admin@acme.com');
    }

    public function test_colors_and_login_texts_are_public_and_the_primary_stays_readable(): void
    {
        // Sin elegir nada: los de IntelectaLab (null)
        $this->getJson($this->api('/tenant'))->assertJsonPath('data.appearance.primary', null)->assertJsonPath('data.appearance.login_title', null);

        // Un naranja claro no se lee con texto blanco: se oscurece y se avisa
        $this->withToken($this->admin())->putJson($this->api('/settings/appearance'), [
            'primary' => '#E4572E', 'accent' => '#17bebb', 'login_title' => ' Tu academia, a tu manera. ', 'login_text' => 'Bienvenido.',
        ])
            ->assertOk()
            ->assertJsonPath('meta.adjusted', true)
            ->assertJsonPath('data.accent', '#17bebb')
            ->assertJsonPath('data.login_title', 'Tu academia, a tu manera.');

        $primary = $this->flushHeaders()->getJson($this->api('/tenant'))->assertOk()->json('data.appearance.primary');
        $this->assertNotSame('#e4572e', $primary);
        $this->assertGreaterThanOrEqual(BrandColor::MIN_CONTRAST, BrandColor::contrastWithWhite($primary));

        // Un color que ya se lee queda igual
        $this->withToken($this->admin())->putJson($this->api('/settings/appearance'), ['primary' => '#0d807e', 'accent' => '#e4572e'])
            ->assertOk()->assertJsonPath('meta.adjusted', false)->assertJsonPath('data.primary', '#0d807e')->assertJsonPath('data.login_title', null);

        // Datos inválidos y quién puede cambiarla
        $this->withToken($this->admin())->putJson($this->api('/settings/appearance'), ['primary' => 'rojo', 'accent' => '#17bebb'])->assertStatus(422);
        $this->withToken($this->admin())->putJson($this->api('/settings/appearance'), ['primary' => '#0d807e'])->assertStatus(422);
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->flushHeaders();
        $this->withToken($this->login('acme', 'ana@acme.com'))->putJson($this->api('/settings/appearance'), ['primary' => '#000000', 'accent' => '#000000'])->assertForbidden();

        // Volver a los de IntelectaLab
        $this->withToken($this->admin())->deleteJson($this->api('/settings/appearance'))->assertOk()->assertJsonPath('data.primary', null);
        $this->flushHeaders()->getJson($this->api('/tenant'))->assertJsonPath('data.appearance.primary', null);
    }

    public function test_the_login_background_is_uploaded_public_and_removed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, base64_decode(self::PNG));

        $url = $this->withToken($this->admin())
            ->post($this->api('/settings/appearance/login-background'), ['file' => new UploadedFile($path, 'sede.png', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.login_background_url');

        $this->assertNotNull($url);
        $this->flushHeaders()->getJson($this->api('/tenant'))->assertJsonPath('data.appearance.login_background_url', $url);

        $this->withToken($this->admin())->deleteJson($this->api('/settings/appearance/login-background'))->assertOk();
        $this->flushHeaders()->getJson($this->api('/tenant'))->assertJsonPath('data.appearance.login_background_url', null);
    }

    public function test_emails_use_the_academy_color(): void
    {
        $admin = $this->admin();
        $texts = ['subject' => 'Te matriculamos', 'body' => 'Ya tienes acceso a {curso}.', 'button' => 'Ir al curso'];

        // Sin colores propios, el azul de IntelectaLab
        $this->assertStringContainsString('background: '.BrandColor::DEFAULT_PRIMARY, $this->withToken($admin)->postJson($this->api('/settings/emails/matricula/preview'), $texts)->assertOk()->json('data.html'));

        $this->withToken($admin)->putJson($this->api('/settings/appearance'), ['primary' => '#0d807e', 'accent' => '#e4572e'])->assertOk();
        $html = $this->withToken($admin)->postJson($this->api('/settings/emails/matricula/preview'), $texts)->assertOk()->json('data.html');

        $this->assertStringContainsString('background: #0d807e', $html);
        $this->assertStringNotContainsString(BrandColor::DEFAULT_PRIMARY, $html);
    }

    public function test_the_site_footer_is_saved_public_and_kept_when_colors_are_reset(): void
    {
        $admin = $this->admin();
        $this->withToken($admin)->putJson($this->api('/settings/appearance'), [
            'primary' => '#0d807e', 'accent' => '#e4572e',
            'footer' => [
                'text' => ' Av. Arequipa 123, Lima ',
                'phone' => '(01) 555-1234',
                'whatsapp' => '+51 987 654 321',
                'social' => ['facebook' => 'https://www.facebook.com/academia', 'instagram' => '', 'tiktok' => null],
            ],
        ])->assertOk()
            ->assertJsonPath('data.footer.text', 'Av. Arequipa 123, Lima')
            ->assertJsonPath('data.footer.whatsapp', '51987654321')
            ->assertJsonPath('data.footer.social', ['facebook' => 'https://www.facebook.com/academia']);

        $this->flushHeaders()->getJson($this->api('/tenant'))->assertJsonPath('data.appearance.footer.phone', '(01) 555-1234');

        // Volver a los colores de IntelectaLab no borra el pie
        $this->withToken($this->admin())->deleteJson($this->api('/settings/appearance'))->assertOk()
            ->assertJsonPath('data.primary', null)
            ->assertJsonPath('data.footer.text', 'Av. Arequipa 123, Lima');

        // Enlaces seguros y redes conocidas
        $this->withToken($this->admin())->putJson($this->api('/settings/appearance'), ['footer' => ['social' => ['facebook' => 'http://inseguro.com']]])->assertStatus(422);
        $this->withToken($this->admin())->putJson($this->api('/settings/appearance'), ['footer' => ['social' => ['myspace' => 'https://myspace.com/x']]])->assertStatus(422);
        $this->withToken($this->admin())->putJson($this->api('/settings/appearance'), ['footer' => ['whatsapp' => 'llámame']])->assertStatus(422);
    }
}
