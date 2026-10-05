<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TC-RBAC-001: un alumno que llama a un endpoint administrativo recibe 403.
 */
class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_call_administrative_endpoint(): void
    {
        $tenant = $this->provisionTenant('acme');
        $student = $this->createUser($tenant, RoleCatalog::STUDENT);

        $this->withToken($this->login('acme', $student->email))
            ->getJson($this->tenantUrl('acme', '/users'))
            ->assertForbidden()
            ->assertJsonPath('code', 'PERMISSION_DENIED')
            ->assertJsonPath('details.permission', 'users.read');

        $this->assertTrue(
            AuditEvent::query()->where('action', 'access.denied')->where('actor_user_id', $student->id)->exists()
        );
    }

    public function test_administrator_wildcard_grants_access(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');

        $this->withToken($this->login('acme', 'admin@acme.com'))
            ->getJson($this->tenantUrl('acme', '/users'))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'last_page']]);
    }

    public function test_session_exposes_role_permissions(): void
    {
        $tenant = $this->provisionTenant('acme');
        $support = $this->createUser($tenant, RoleCatalog::SUPPORT);

        $permissions = $this->postJson($this->tenantUrl('acme', '/auth/login'), [
            'email' => $support->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.permissions');

        $this->assertEqualsCanonicalizing(
            RoleCatalog::defaults()[RoleCatalog::SUPPORT]['permissions'],
            $permissions
        );
    }
}
