<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'organization_name' => 'Academia Central',
            'subdomain' => 'academia',
            'first_name' => 'Carla',
            'last_name' => 'Mendoza',
            'email' => 'Carla@Academia.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    public function test_registration_provisions_tenant_roles_and_admin(): void
    {
        $this->postJson($this->centralUrl('/tenants'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'academia');

        $tenant = Tenant::query()->where('slug', 'academia')->firstOrFail();

        $this->assertSame(
            array_keys(RoleCatalog::defaults()),
            Role::query()->where('tenant_id', $tenant->id)->orderBy('created_at')->orderBy('id')->pluck('code')->all()
        );
        $this->assertTrue(
            AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', 'tenants.provisioned')->exists()
        );

        // El administrador inicia sesión en su subdominio con acceso total
        $this->postJson($this->tenantUrl('academia', '/auth/login'), [
            'email' => 'carla@academia.com',
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'academia')
            ->assertJsonPath('data.user.roles', ['Administrador'])
            ->assertJsonPath('data.permissions', ['*']);
    }

    public function test_registration_is_only_available_on_central_domain(): void
    {
        $this->provisionTenant('acme');

        $this->postJson($this->tenantUrl('acme', '/tenants'), $this->payload())
            ->assertNotFound()
            ->assertJsonPath('code', 'CENTRAL_ONLY');
    }

    public function test_subdomain_must_be_valid_unique_and_not_reserved(): void
    {
        $this->provisionTenant('acme');

        foreach (['acme', 'admin', 'Con Espacios', '-guion', 'ab'] as $subdomain) {
            $this->postJson($this->centralUrl('/tenants'), $this->payload(['subdomain' => $subdomain]))
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR')
                ->assertJsonStructure(['details' => ['subdomain']]);
        }
    }
}
