<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Correo de envío de cada academia: el suyo propio o el de la plataforma
 * con su nombre; si el propio falla, el aviso sale igual.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'avisos@intelectalab.com']);

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
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
            'from_name' => 'Acme Cursos',
            'from_address' => 'cursos@acme.com',
            'reply_to' => 'ayuda@acme.com',
            'host' => '127.0.0.1',
            'port' => 1,
            'security' => 'none',
            'username' => 'cursos@acme.com',
            'password' => 'clave-secreta-123',
        ];
    }

    private function sender(TenantNoticeMail $mail): string
    {
        return "{$mail->from[0]['name']} <{$mail->from[0]['address']}>";
    }

    private function notice(): void
    {
        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => app(TenantNotifier::class)
            ->notifyEmail('luis@correo.com', 'Luis', 'cuenta_nueva', ['correo' => 'luis@correo.com'], '/'));
    }

    public function test_by_default_notices_go_through_the_platform_with_the_academy_name(): void
    {
        Mail::fake();

        $this->withToken($this->admin)->getJson($this->api('/settings/mail'))
            ->assertOk()
            ->assertJsonPath('data.mode', 'PLATFORM')
            ->assertJsonPath('data.defaults.from_name', $this->tenant->name)
            ->assertJsonPath('data.defaults.platform_address', 'avisos@intelectalab.com');

        $this->withToken($this->admin)->putJson($this->api('/settings/mail'), ['mode' => 'PLATFORM', 'from_name' => 'Acme Cursos', 'reply_to' => 'ayuda@acme.com'])->assertOk();

        $this->notice();

        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('luis@correo.com')
            && $this->sender($mail) === 'Acme Cursos <avisos@intelectalab.com>'
            && $mail->replyTo[0]['address'] === 'ayuda@acme.com');
    }

    public function test_the_own_server_password_is_kept_secret_and_a_failure_falls_back_to_the_platform(): void
    {
        Mail::fake();

        $this->withToken($this->admin)->putJson($this->api('/settings/mail'), $this->deadServer())
            ->assertOk()
            ->assertJsonPath('data.mode', 'OWN')
            ->assertJsonPath('data.host', '127.0.0.1')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissingPath('data.password');

        // Cifrada en la base
        $this->assertStringNotContainsString('clave-secreta-123', (string) DB::table('tenant_mail_settings')->value('smtp'));

        // Guardar sin escribir la contraseña la conserva
        $this->withToken($this->admin)->putJson($this->api('/settings/mail'), ['password' => null] + $this->deadServer())->assertJsonPath('data.has_password', true);

        // La prueba usa solo el servidor propio y dice por qué falló
        $this->withToken($this->admin)->postJson($this->api('/settings/mail/test'), $this->deadServer())
            ->assertStatus(422)
            ->assertJsonPath('code', 'BAD_REQUEST');
        Mail::assertNothingSent();

        // Un aviso de verdad no se pierde: sale por la plataforma, y la academia ve el error
        $this->notice();

        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('luis@correo.com')
            && $this->sender($mail) === 'Acme Cursos <avisos@intelectalab.com>'
            && $mail->replyTo[0]['address'] === 'ayuda@acme.com');

        $this->withToken($this->admin)->getJson($this->api('/settings/mail'))
            ->assertJsonPath('data.last_sent_at', null)
            ->assertJson(fn ($json) => $json->where('data.last_error', fn ($error) => filled($error))->etc());
    }

    public function test_the_own_server_needs_its_data_and_only_admins_change_it(): void
    {
        $this->withToken($this->admin)->putJson($this->api('/settings/mail'), ['mode' => 'OWN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from_address', 'host', 'port'], 'details');

        $this->withToken($this->admin)->putJson($this->api('/settings/mail'), ['host' => 'smtp://servidor'] + $this->deadServer())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host'], 'details');

        $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'rosa@acme.com']);
        $this->flushHeaders()->withToken($this->login('acme', 'rosa@acme.com'))->getJson($this->api('/settings/mail'))->assertForbidden();
    }
}
