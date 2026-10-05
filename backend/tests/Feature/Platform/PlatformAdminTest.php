<?php

namespace Tests\Feature\Platform;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private function platformToken(): string
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);

        return $this->postJson($this->centralUrl('/platform/auth/login'), [
            'email' => 'root@plataforma.test',
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');
    }

    public function test_platform_admin_lists_and_suspends_organizations(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $tenantToken = $this->login('alfa', 'admin@alfa.com');
        $token = $this->platformToken();

        $this->withToken($token)->getJson($this->centralUrl('/platform/tenants'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.slug', 'alfa')
            ->assertJsonPath('data.0.users_count', 1);

        $this->withToken($token)
            ->patchJson($this->centralUrl("/platform/tenants/{$alfa->id}/status"), ['status' => 'SUSPENDED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'SUSPENDED');

        // La organización suspendida queda bloqueada, incluso con un token vigente
        $this->withToken($tenantToken)->getJson($this->tenantUrl('alfa', '/auth/me'))
            ->assertForbidden()
            ->assertJsonPath('code', 'TENANT_INACTIVE');

        $this->assertTrue(
            AuditEvent::query()->where('tenant_id', $alfa->id)->where('action', 'tenants.status_changed')->exists()
        );
    }

    public function test_tenant_administrator_wildcard_does_not_reach_platform_operations(): void
    {
        $this->provisionTenant('alfa', 'admin@alfa.com');
        $tenantToken = $this->login('alfa', 'admin@alfa.com');

        // En el dominio central su token no identifica a nadie
        $this->withToken($tenantToken)->getJson($this->centralUrl('/platform/tenants'))->assertUnauthorized();

        // Y desde su subdominio las rutas de plataforma no existen
        $this->withToken($tenantToken)->getJson($this->tenantUrl('alfa', '/platform/tenants'))
            ->assertNotFound()
            ->assertJsonPath('code', 'CENTRAL_ONLY');
    }

    public function test_platform_token_is_not_valid_inside_an_organization(): void
    {
        $this->provisionTenant('alfa');
        $token = $this->platformToken();

        foreach (['/auth/me', '/users', '/roles'] as $path) {
            $this->withToken($token)->getJson($this->tenantUrl('alfa', $path))->assertUnauthorized();
        }
    }

    public function test_platform_routes_require_authentication_and_valid_credentials(): void
    {
        $this->getJson($this->centralUrl('/platform/tenants'))->assertUnauthorized();

        $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'nadie@plataforma.test', 'password' => 'x'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }
}
