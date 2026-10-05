<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Mail\PasswordResetMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'otra-clave-segura';

    /**
     * Solicita la recuperación y devuelve los parámetros del enlace enviado por correo.
     *
     * @return array{token: string, email: string}
     */
    private function requestResetLink(string $slug, string $email): array
    {
        Mail::fake();

        $this->postJson($this->tenantUrl($slug, '/auth/forgot-password'), ['email' => $email])->assertOk();

        $link = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$link, $email, $slug) {
            $link = $mail->url;

            return $mail->hasTo($email) && str_starts_with($mail->url, "http://{$slug}.localhost/reset-password?");
        });

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_user_recovers_access_with_the_emailed_link(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $oldToken = $this->login('acme', 'admin@acme.com');
        $link = $this->requestResetLink('acme', 'admin@acme.com');

        $payload = $link + ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $this->postJson($this->tenantUrl('acme', '/auth/reset-password'), $payload)->assertOk();

        // La clave nueva funciona, la anterior no, y las sesiones abiertas se cerraron
        $this->postJson($this->tenantUrl('acme', '/auth/login'), ['email' => 'admin@acme.com', 'password' => self::NEW_PASSWORD])
            ->assertOk();
        $this->postJson($this->tenantUrl('acme', '/auth/login'), ['email' => 'admin@acme.com', 'password' => self::PASSWORD])
            ->assertUnauthorized();
        $this->withToken($oldToken)->getJson($this->tenantUrl('acme', '/auth/me'))->assertUnauthorized();

        // El enlace es de un solo uso
        $this->postJson($this->tenantUrl('acme', '/auth/reset-password'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_RESET_TOKEN');

        $this->assertTrue(AuditEvent::query()->where('action', 'auth.password_reset')->exists());
    }

    public function test_forgot_password_does_not_reveal_whether_the_account_exists(): void
    {
        Mail::fake();
        $this->provisionTenant('acme');

        $this->postJson($this->tenantUrl('acme', '/auth/forgot-password'), ['email' => 'nadie@acme.com'])
            ->assertOk()
            ->assertExactJson(['data' => null]);

        Mail::assertNothingSent();
    }

    public function test_reset_link_expires_and_only_works_in_its_own_organization(): void
    {
        $this->provisionTenant('alfa', 'persona@correo.com');
        $this->provisionTenant('beta', 'persona@correo.com');
        $link = $this->requestResetLink('alfa', 'persona@correo.com');
        $payload = $link + ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        // Mismo correo en otra organización: el enlace de alfa no sirve en beta
        $this->postJson($this->tenantUrl('beta', '/auth/reset-password'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_RESET_TOKEN');

        $this->travel(config('identity.password_reset_minutes') + 1)->minutes();

        $this->postJson($this->tenantUrl('alfa', '/auth/reset-password'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_RESET_TOKEN');
    }

    public function test_user_changes_own_password_and_other_sessions_are_closed(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $other = $this->login('acme', 'admin@acme.com');
        $current = $this->login('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/auth/password');
        $new = ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $this->withToken($current)->putJson($url, ['current_password' => 'incorrecta'] + $new)
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['current_password']]);

        $this->withToken($current)->putJson($url, ['current_password' => self::PASSWORD] + $new)->assertOk();

        $this->withToken($current)->getJson($this->tenantUrl('acme', '/auth/me'))->assertOk();
        $this->withToken($other)->getJson($this->tenantUrl('acme', '/auth/me'))->assertUnauthorized();
    }

    public function test_user_lists_and_revokes_own_sessions(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $student = $this->createUser($tenant, RoleCatalog::STUDENT);
        $first = $this->login('acme', 'admin@acme.com');
        $second = $this->login('acme', 'admin@acme.com');
        $studentToken = $this->login('acme', $student->email);

        $sessions = $this->withToken($second)->getJson($this->tenantUrl('acme', '/auth/sessions'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'current', 'ip_address', 'user_agent', 'created_at', 'expires_at']]])
            ->json('data');

        $this->assertSame([true, false], collect($sessions)->sortByDesc('current')->pluck('current')->all());
        $otherId = collect($sessions)->firstWhere('current', false)['id'];

        // No se puede cerrar la sesión de otro usuario
        $studentSessionId = $this->withToken($studentToken)->getJson($this->tenantUrl('acme', '/auth/sessions'))->json('data.0.id');
        $this->withToken($second)->deleteJson($this->tenantUrl('acme', "/auth/sessions/{$studentSessionId}"))->assertNotFound();

        $this->withToken($second)->deleteJson($this->tenantUrl('acme', "/auth/sessions/{$otherId}"))->assertOk();
        $this->withToken($first)->getJson($this->tenantUrl('acme', '/auth/me'))->assertUnauthorized();
        $this->withToken($studentToken)->getJson($this->tenantUrl('acme', '/auth/me'))->assertOk();
    }

    public function test_sessions_expire_according_to_policy(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');

        $this->travel(config('identity.session_lifetime_minutes') - 1)->minutes();
        $this->withToken($token)->getJson($this->tenantUrl('acme', '/auth/me'))->assertOk();

        $this->travel(2)->minutes();
        $this->withToken($token)->getJson($this->tenantUrl('acme', '/auth/me'))->assertUnauthorized();
    }
}
