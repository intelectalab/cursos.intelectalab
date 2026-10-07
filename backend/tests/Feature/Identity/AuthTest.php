<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_session_with_token_and_permissions(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');

        $this->postJson($this->tenantUrl('acme', '/auth/login'), ['email' => 'ADMIN@acme.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'first_name', 'last_name', 'roles'], 'tenant', 'permissions']])
            ->assertJsonPath('data.user.email', 'admin@acme.com')
            ->assertJsonPath('data.tenant.id', $tenant->id);
    }

    public function test_wrong_password_is_rejected_and_throttled(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/auth/login');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($url, ['email' => 'admin@acme.com', 'password' => 'incorrecta'])
                ->assertUnauthorized()
                ->assertJsonPath('code', 'INVALID_CREDENTIALS');
        }

        $this->postJson($url, ['email' => 'admin@acme.com', 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_account_locks_after_repeated_failures_from_any_address(): void
    {
        config(['identity.login_lock_attempts' => 3]);
        $this->provisionTenant('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/auth/login');

        // Cada fallo llega desde una dirección distinta: el límite por dirección no alcanza
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson($url, ['email' => 'admin@acme.com', 'password' => 'incorrecta'])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->postJson($url, ['email' => 'admin@acme.com', 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('code', 'ACCOUNT_LOCKED');

        $this->assertSame(3, AuditEvent::query()->where('action', 'auth.login_failed')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'auth.locked')->count());

        // Pasado el plazo, la cuenta vuelve a admitir el acceso y queda registrado
        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->postJson($url, ['email' => 'admin@acme.com', 'password' => self::PASSWORD])
            ->assertOk();

        $this->assertSame(1, AuditEvent::query()->where('action', 'auth.login')->count());
    }

    public function test_a_lock_in_one_organization_does_not_reach_the_same_email_in_another(): void
    {
        config(['identity.login_lock_attempts' => 2]);
        $this->provisionTenant('alfa', 'persona@correo.com');
        $this->provisionTenant('beta', 'persona@correo.com');

        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson($this->tenantUrl('alfa', '/auth/login'), ['email' => 'persona@correo.com', 'password' => 'incorrecta'])
                ->assertUnauthorized();
        }

        $this->postJson($this->tenantUrl('beta', '/auth/login'), ['email' => 'persona@correo.com', 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $tenant = $this->provisionTenant('acme');
        $user = $this->createUser($tenant, RoleCatalog::STUDENT, ['status' => 'INACTIVE']);

        $this->postJson($this->tenantUrl('acme', '/auth/login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_validation_errors_use_standard_format(): void
    {
        $this->provisionTenant('acme');

        $this->postJson($this->tenantUrl('acme', '/auth/login'), ['email' => 'no-es-correo'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['details' => ['email', 'password'], 'trace_id']);
    }

    public function test_me_requires_authentication_and_logout_revokes_token(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');

        $this->getJson($this->tenantUrl('acme', '/auth/me'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $token = $this->login('acme', 'admin@acme.com');

        $this->withToken($token)->getJson($this->tenantUrl('acme', '/auth/me'))
            ->assertOk()
            ->assertJsonPath('data.user.email', 'admin@acme.com')
            ->assertJsonMissingPath('data.token');

        $this->withToken($token)->postJson($this->tenantUrl('acme', '/auth/logout'))->assertOk();

        $this->withToken($token)->getJson($this->tenantUrl('acme', '/auth/me'))->assertUnauthorized();
    }
}
