<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Application\SyncAccessCatalog;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Permission;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(string $slug = 'acme'): string
    {
        $this->provisionTenant($slug, "admin@{$slug}.com");

        return $this->login($slug, "admin@{$slug}.com");
    }

    public function test_catalog_is_grouped_by_module_and_hides_the_global_wildcard(): void
    {
        $modules = $this->withToken($this->adminToken())
            ->getJson($this->tenantUrl('acme', '/permissions'))
            ->assertOk()
            ->assertJsonStructure(['data' => [['module', 'name', 'permissions' => [['code', 'description']]]]])
            ->json('data');

        $codes = collect($modules)->flatMap(fn ($module) => array_column($module['permissions'], 'code'));

        $this->assertContains('courses.publish', $codes);
        $this->assertNotContains('*', $codes);
    }

    public function test_organization_creates_a_custom_role_and_assigns_it(): void
    {
        $token = $this->adminToken();
        $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
        $user = $this->createUser($tenant, RoleCatalog::STUDENT);

        $roleId = $this->withToken($token)->postJson($this->tenantUrl('acme', '/roles'), [
            'name' => 'Coordinador académico',
            'description' => 'Publica cursos y revisa reportes',
            'permissions' => ['courses.read', 'courses.publish', 'reports.read', 'users.read'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions', ['courses.publish', 'courses.read', 'reports.read', 'users.read'])
            ->json('data.id');

        $this->withToken($token)->putJson($this->tenantUrl('acme', "/users/{$user->id}/roles"), ['roles' => [$roleId]])
            ->assertOk()
            ->assertJsonPath('data.roles', ['Coordinador académico']);

        // El usuario ahora accede a lo que su rol propio le otorga, y a nada más
        $userToken = $this->login('acme', $user->email);
        $this->withToken($userToken)->getJson($this->tenantUrl('acme', '/users'))->assertOk();
        $this->withToken($userToken)->getJson($this->tenantUrl('acme', '/roles'))->assertForbidden();

        foreach (['roles.created', 'users.roles_updated'] as $action) {
            $this->assertTrue(AuditEvent::query()->where('action', $action)->exists(), $action);
        }
    }

    public function test_role_validation_rejects_wildcard_unknown_codes_and_duplicate_names(): void
    {
        $token = $this->adminToken();
        $url = $this->tenantUrl('acme', '/roles');

        foreach (['*', 'platform.tenants.suspend', 'no.existe'] as $code) {
            $this->withToken($token)->postJson($url, ['name' => 'Rol X', 'permissions' => [$code]])
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }

        $this->withToken($token)->postJson($url, ['name' => 'Administrador', 'permissions' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['name']]);
    }

    public function test_system_roles_cannot_be_edited_or_deleted(): void
    {
        $token = $this->adminToken();
        $roleId = Role::query()->where('code', RoleCatalog::STUDENT)->value('id');

        $this->withToken($token)->putJson($this->tenantUrl('acme', "/roles/{$roleId}"), ['name' => 'Otro', 'permissions' => []])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ROLE_IS_SYSTEM');

        $this->withToken($token)->deleteJson($this->tenantUrl('acme', "/roles/{$roleId}"))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ROLE_IS_SYSTEM');
    }

    public function test_custom_role_in_use_cannot_be_deleted_until_unassigned(): void
    {
        $token = $this->adminToken();
        $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
        $user = $this->createUser($tenant, RoleCatalog::STUDENT);
        $studentRoleId = Role::query()->where('code', RoleCatalog::STUDENT)->value('id');

        $roleId = $this->withToken($token)
            ->postJson($this->tenantUrl('acme', '/roles'), ['name' => 'Temporal', 'permissions' => ['reports.read']])
            ->json('data.id');
        $this->withToken($token)->putJson($this->tenantUrl('acme', "/users/{$user->id}/roles"), ['roles' => [$roleId]])->assertOk();

        $this->withToken($token)->deleteJson($this->tenantUrl('acme', "/roles/{$roleId}"))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ROLE_IN_USE');

        $this->withToken($token)->putJson($this->tenantUrl('acme', "/users/{$user->id}/roles"), ['roles' => [$studentRoleId]])->assertOk();
        $this->withToken($token)->deleteJson($this->tenantUrl('acme', "/roles/{$roleId}"))->assertOk();
    }

    public function test_user_cannot_grant_permissions_they_do_not_have(): void
    {
        $token = $this->adminToken();
        $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
        $manager = $this->createUser($tenant, RoleCatalog::STUDENT);
        $adminRoleId = Role::query()->where('code', RoleCatalog::ADMIN)->value('id');

        // Un rol propio que solo administra roles y usuarios
        $roleId = $this->withToken($token)->postJson($this->tenantUrl('acme', '/roles'), [
            'name' => 'Gestor de accesos',
            'permissions' => ['roles.read', 'roles.create', 'users.read', 'users.update'],
        ])->json('data.id');
        $this->withToken($token)->putJson($this->tenantUrl('acme', "/users/{$manager->id}/roles"), ['roles' => [$roleId]])->assertOk();

        $managerToken = $this->login('acme', $manager->email);

        $this->withToken($managerToken)->postJson($this->tenantUrl('acme', '/roles'), [
            'name' => 'Escalado',
            'permissions' => ['payments.refund'],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'PRIVILEGE_ESCALATION')
            ->assertJsonPath('details.permissions', ['payments.refund']);

        // Tampoco puede hacerse administrador asignándose el rol de sistema
        $this->withToken($managerToken)
            ->putJson($this->tenantUrl('acme', "/users/{$manager->id}/roles"), ['roles' => [$adminRoleId]])
            ->assertForbidden()
            ->assertJsonPath('code', 'PRIVILEGE_ESCALATION');
    }

    public function test_organization_keeps_at_least_one_administrator(): void
    {
        $token = $this->adminToken();
        $admin = app(TenantContext::class)->runAsSystem(fn () => User::query()->where('email', 'admin@acme.com')->firstOrFail());
        $studentRoleId = Role::query()->where('code', RoleCatalog::STUDENT)->value('id');

        $this->withToken($token)->putJson($this->tenantUrl('acme', "/users/{$admin->id}/roles"), ['roles' => [$studentRoleId]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LAST_ADMINISTRATOR');
    }

    public function test_roles_of_another_organization_are_not_reachable(): void
    {
        $alfa = $this->adminToken('alfa');
        $beta = $this->adminToken('beta');

        $betaRoleId = $this->withToken($beta)
            ->postJson($this->tenantUrl('beta', '/roles'), ['name' => 'Rol de Beta', 'permissions' => ['reports.read']])
            ->json('data.id');

        $this->withToken($alfa)->putJson($this->tenantUrl('alfa', "/roles/{$betaRoleId}"), ['name' => 'Robado', 'permissions' => []])
            ->assertNotFound();
        $this->withToken($alfa)->deleteJson($this->tenantUrl('alfa', "/roles/{$betaRoleId}"))->assertNotFound();

        $names = $this->withToken($alfa)->getJson($this->tenantUrl('alfa', '/roles'))->json('data.*.name');
        $this->assertNotContains('Rol de Beta', $names);

        // Mismo nombre en otra organización: permitido
        $this->withToken($alfa)
            ->postJson($this->tenantUrl('alfa', '/roles'), ['name' => 'Rol de Beta', 'permissions' => []])
            ->assertCreated();
    }

    public function test_catalog_sync_updates_system_roles_and_keeps_custom_ones(): void
    {
        $token = $this->adminToken();
        $customId = $this->withToken($token)
            ->postJson($this->tenantUrl('acme', '/roles'), ['name' => 'Propio', 'permissions' => ['reports.read']])
            ->json('data.id');

        // Simula un rol de sistema desactualizado respecto del catálogo
        $student = Role::query()->where('code', RoleCatalog::STUDENT)->firstOrFail();
        $student->permissions()->sync([Permission::query()->where('code', 'student.portal')->value('id')]);

        $this->assertSame(1, app(SyncAccessCatalog::class)->handle());

        $this->assertEqualsCanonicalizing(
            RoleCatalog::defaults()[RoleCatalog::STUDENT]['permissions'],
            $student->permissions()->pluck('code')->all()
        );
        $this->assertSame(['reports.read'], Role::query()->findOrFail($customId)->permissions()->pluck('code')->all());
    }
}
