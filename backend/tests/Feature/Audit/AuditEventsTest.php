<?php

namespace Tests\Feature\Audit;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_consults_and_filters_the_audit_log(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/audit-events');

        $this->withToken($token)
            ->postJson($this->tenantUrl('acme', '/roles'), ['name' => 'Auditable', 'permissions' => ['reports.read']])
            ->assertCreated();

        $this->withToken($token)->getJson($url)
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'roles.created')
            ->assertJsonPath('data.0.actor.email', 'admin@acme.com')
            ->assertJsonPath('data.0.after.permissions', ['reports.read'])
            ->assertJsonPath('data.1.action', 'tenants.provisioned');

        $this->withToken($token)->getJson($url.'?action=roles')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.entity_type', 'roles');

        $this->withToken($token)->getJson($url.'?from='.now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
    }

    public function test_audit_log_is_isolated_and_requires_permission(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $student = $this->createUser($alfa, RoleCatalog::STUDENT);

        $entities = $this->withToken($this->login('alfa', 'admin@alfa.com'))
            ->getJson($this->tenantUrl('alfa', '/audit-events'))
            ->assertOk()
            ->json('data.*.entity_id');

        $this->assertSame([$alfa->id], $entities);

        $this->withToken($this->login('alfa', $student->email))
            ->getJson($this->tenantUrl('alfa', '/audit-events'))
            ->assertForbidden();
    }
}
