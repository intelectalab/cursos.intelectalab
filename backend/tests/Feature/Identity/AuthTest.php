<?php

namespace Tests\Feature\Identity;

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
