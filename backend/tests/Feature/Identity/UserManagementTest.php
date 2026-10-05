<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Lucía',
            'last_name' => 'Paredes',
            'email' => 'Lucia@acme.com',
            'phone' => '999 888 777',
            'password' => self::PASSWORD,
            'roles' => [Role::query()->where('code', RoleCatalog::TEACHER)->value('id')],
        ];
    }

    public function test_administrator_creates_a_user_who_can_log_in(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');

        $this->withToken($token)->postJson($this->tenantUrl('acme', '/users'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.email', 'lucia@acme.com')
            ->assertJsonPath('data.roles', ['Profesor'])
            ->assertJsonMissingPath('data.password');

        $this->postJson($this->tenantUrl('acme', '/auth/login'), ['email' => 'lucia@acme.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.roles', ['Profesor']);

        $this->assertTrue(AuditEvent::query()->where('action', 'users.created')->exists());
    }

    public function test_email_is_unique_per_organization_only(): void
    {
        $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $alfa = $this->login('alfa', 'admin@alfa.com');
        $beta = $this->login('beta', 'admin@beta.com');
        $roleOf = fn (string $slug) => app(TenantContext::class)->runAsSystem(fn () => Role::query()
            ->where('code', RoleCatalog::TEACHER)
            ->whereRelation('tenant', 'slug', $slug)
            ->value('id'));

        $this->withToken($alfa)->postJson($this->tenantUrl('alfa', '/users'), $this->payload(['roles' => [$roleOf('alfa')]]))
            ->assertCreated();
        $this->withToken($alfa)->postJson($this->tenantUrl('alfa', '/users'), $this->payload(['roles' => [$roleOf('alfa')]]))
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['email']]);

        // El mismo correo sí puede existir en otra organización
        $this->withToken($beta)->postJson($this->tenantUrl('beta', '/users'), $this->payload(['roles' => [$roleOf('beta')]]))
            ->assertCreated();

        // Y no se puede asignar un rol de otra organización
        $this->withToken($alfa)
            ->postJson($this->tenantUrl('alfa', '/users'), $this->payload(['email' => 'otro@alfa.com', 'roles' => [$roleOf('beta')]]))
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['roles.0']]);
    }

    public function test_deactivated_user_loses_access_immediately(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $user = $this->createUser($tenant, RoleCatalog::STUDENT);
        $adminToken = $this->login('acme', 'admin@acme.com');
        $userToken = $this->login('acme', $user->email);

        $this->withToken($adminToken)->putJson($this->tenantUrl('acme', "/users/{$user->id}"), [
            'first_name' => 'Nombre',
            'last_name' => 'Editado',
            'phone' => null,
            'status' => User::STATUS_INACTIVE,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE')
            ->assertJsonPath('data.last_name', 'Editado');

        $this->withToken($userToken)->getJson($this->tenantUrl('acme', '/auth/me'))->assertUnauthorized();
        $this->postJson($this->tenantUrl('acme', '/auth/login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_last_administrator_cannot_be_deactivated(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $admin = app(TenantContext::class)->runAsSystem(fn () => User::query()->where('email', 'admin@acme.com')->firstOrFail());

        $this->withToken($token)->putJson($this->tenantUrl('acme', "/users/{$admin->id}"), [
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'status' => User::STATUS_INACTIVE,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LAST_ADMINISTRATOR');
    }

    public function test_user_document_is_validated_by_type_and_unique_per_organization(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/users');

        $id = $this->withToken($token)
            ->postJson($url, $this->payload(['doc_type' => 'DNI', 'doc_number' => '45678912']))
            ->assertCreated()
            ->assertJsonPath('data.doc_type', 'DNI')
            ->assertJsonPath('data.doc_number', '45678912')
            ->json('data.id');

        $invalid = [
            ['doc_type' => 'DNI', 'doc_number' => '1234'],          // el DNI tiene 8 dígitos
            ['doc_type' => 'DNI', 'doc_number' => '45678912'],      // repetido en la organización
            ['doc_type' => 'CEDULA', 'doc_number' => '45678912'],   // tipo desconocido
            ['doc_type' => 'PASAPORTE'],                            // falta el número
        ];

        foreach ($invalid as $i => $document) {
            $this->withToken($token)
                ->postJson($url, $this->payload(['email' => "otro{$i}@acme.com"] + $document))
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }

        // Editar al mismo usuario conservando su documento no choca consigo mismo
        $this->withToken($token)->putJson("{$url}/{$id}", [
            'first_name' => 'Lucía',
            'last_name' => 'Paredes',
            'doc_type' => 'DNI',
            'doc_number' => '45678912',
            'status' => User::STATUS_ACTIVE,
        ])->assertOk();

        // El documento es opcional
        $this->withToken($token)->postJson($url, $this->payload(['email' => 'sin-doc@acme.com']))
            ->assertCreated()
            ->assertJsonPath('data.doc_type', null);
    }

    public function test_user_management_requires_permissions(): void
    {
        $tenant = $this->provisionTenant('acme');
        $student = $this->createUser($tenant, RoleCatalog::STUDENT);
        $token = $this->login('acme', $student->email);

        $this->withToken($token)->postJson($this->tenantUrl('acme', '/users'), $this->payload())->assertForbidden();
        $this->withToken($token)->putJson($this->tenantUrl('acme', "/users/{$student->id}"), [])->assertForbidden();
    }
}
