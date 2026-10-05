<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subdomain_resolves_its_tenant(): void
    {
        $tenant = $this->provisionTenant('acme');

        $this->getJson($this->tenantUrl('acme', '/tenant'))
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonPath('data.slug', 'acme')
            ->assertJsonPath('meta.central_domain', 'localhost');
    }

    public function test_central_domain_has_no_tenant(): void
    {
        $this->getJson($this->centralUrl('/tenant'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_unknown_subdomain_returns_standard_error(): void
    {
        $response = $this->getJson($this->tenantUrl('nadie', '/tenant'), ['X-Request-ID' => 'req-12345678']);

        $response->assertNotFound()
            ->assertJsonPath('code', 'TENANT_NOT_FOUND')
            ->assertJsonPath('trace_id', 'req-12345678')
            ->assertJsonStructure(['code', 'message', 'details', 'trace_id'])
            ->assertHeader('X-Request-ID', 'req-12345678');
    }

    public function test_inactive_tenant_is_blocked(): void
    {
        $tenant = $this->provisionTenant('acme');
        Tenant::query()->whereKey($tenant->id)->update(['status' => 'SUSPENDED']);

        $this->getJson($this->tenantUrl('acme', '/tenant'))
            ->assertForbidden()
            ->assertJsonPath('code', 'TENANT_INACTIVE');
    }

    public function test_tenant_routes_are_not_available_on_central_domain(): void
    {
        $this->postJson($this->centralUrl('/auth/login'), ['email' => 'a@b.com', 'password' => 'x'])
            ->assertNotFound()
            ->assertJsonPath('code', 'TENANT_REQUIRED');
    }
}
