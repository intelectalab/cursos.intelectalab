<?php

namespace Tests;

use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const PASSWORD = 'clave-de-prueba';

    protected function tenantUrl(string $slug, string $path): string
    {
        return "http://{$slug}.localhost/api/v1{$path}";
    }

    protected function centralUrl(string $path): string
    {
        return "http://localhost/api/v1{$path}";
    }

    protected function provisionTenant(string $slug, string $adminEmail = 'admin@example.com'): Tenant
    {
        return app(ProvisionTenant::class)->handle(
            ['name' => "Organización {$slug}", 'slug' => $slug],
            ['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => $adminEmail, 'password' => self::PASSWORD]
        );
    }

    protected function createUser(Tenant $tenant, string $roleCode, array $attributes = []): User
    {
        return app(TenantContext::class)->runForTenant($tenant->id, function () use ($roleCode, $attributes) {
            $user = User::factory()->create(['password' => self::PASSWORD] + $attributes);
            $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

            return $user;
        });
    }

    protected function login(string $slug, string $email): string
    {
        return $this->postJson($this->tenantUrl($slug, '/auth/login'), [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');
    }

    /**
     * Petición autenticada. Olvida los guards para que cada petición del test
     * resuelva su usuario desde el token, como en producción.
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }
}
