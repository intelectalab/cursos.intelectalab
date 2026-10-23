<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Correo de envío de la plataforma: el super administrador elige el del
 * servidor o uno propio desde la consola; si el propio falla, sale igual.
 */
class PlatformMailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'avisos@intelectalab.com', 'mail.from.name' => 'IntelectaLab']);

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);
        $this->token = $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->json('data.token');
    }

    private function api(string $path): string
    {
        return $this->centralUrl($path);
    }

    /**
     * Servidor propio que no responde: nada escucha en el puerto 1.
     *
     * @return array<string, mixed>
     */
    private function deadServer(): array
    {
        return [
            'mode' => 'OWN',
            'from_name' => 'IntelectaLab Avisos',
            'from_address' => 'hola@intelectalab.com',
            'host' => '127.0.0.1',
            'port' => 1,
            'security' => 'none',
            'username' => 'hola@intelectalab.com',
            'password' => 'clave-secreta-456',
        ];
    }

    // Aviso de una academia que usa «El correo de IntelectaLab»
    private function academyNotice(): void
    {
        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => app(TenantNotifier::class)
            ->notifyEmail('luis@correo.com', 'Luis', 'cuenta_nueva', ['correo' => 'luis@correo.com'], '/'));
    }

    public function test_by_default_the_platform_uses_the_server_mail(): void
    {
        Mail::fake();

        $this->withToken($this->token)->getJson($this->api('/platform/mail'))
            ->assertOk()
            ->assertJsonPath('data.mode', 'PLATFORM')
            ->assertJsonPath('data.server.from_address', 'avisos@intelectalab.com');

        $this->academyNotice();

        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('luis@correo.com') && $mail->from[0]['address'] === 'avisos@intelectalab.com');
    }

    public function test_the_platform_admin_sets_its_own_server_and_a_failure_falls_back_to_the_server(): void
    {
        Mail::fake();

        $this->withToken($this->token)->putJson($this->api('/platform/mail'), $this->deadServer())
            ->assertOk()
            ->assertJsonPath('data.mode', 'OWN')
            ->assertJsonPath('data.host', '127.0.0.1')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissingPath('data.password');

        // Cifrada en la base
        $this->assertStringNotContainsString('clave-secreta-456', (string) DB::table('platform_settings')->where('key', 'platform_mail')->value('value'));

        // La prueba usa solo el servidor propio y dice por qué falló
        $this->withToken($this->token)->postJson($this->api('/platform/mail/test'), $this->deadServer() + ['test_to' => 'yo@correo.com'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BAD_REQUEST');
        Mail::assertNothingSent();

        // Un correo de verdad no se pierde: sale por el del servidor y la consola ve el error
        $this->academyNotice();
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('luis@correo.com') && $mail->from[0]['address'] === 'avisos@intelectalab.com');

        $this->withToken($this->token)->getJson($this->api('/platform/mail'))
            ->assertJson(fn ($json) => $json->where('data.last_error', fn ($error) => filled($error))->etc());

        // Volver al del servidor
        $this->withToken($this->token)->putJson($this->api('/platform/mail'), ['mode' => 'PLATFORM'])->assertOk()->assertJsonPath('data.mode', 'PLATFORM');
    }

    public function test_only_the_platform_admin_changes_it(): void
    {
        $academyAdmin = $this->login('acme', 'admin@acme.com');

        // La sesión de una academia no vale en la consola
        $this->flushHeaders()->withToken($academyAdmin)->getJson($this->api('/platform/mail'))->assertUnauthorized();
        $this->flushHeaders()->withToken($academyAdmin)->putJson($this->api('/platform/mail'), $this->deadServer())->assertUnauthorized();
        $this->flushHeaders()->putJson($this->api('/platform/mail'), $this->deadServer())->assertUnauthorized();
    }
}
