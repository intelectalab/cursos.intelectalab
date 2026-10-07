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

    public function test_platform_admin_sees_figures_per_organization_and_creates_one(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $this->createUser($alfa, 'STUDENT');
        $this->createUser($alfa, 'STUDENT', ['status' => 'INACTIVE']);
        $this->login('alfa', 'admin@alfa.com');
        $token = $this->platformToken();

        $this->withToken($token)->getJson($this->centralUrl('/platform/tenants'))
            ->assertOk()
            ->assertJsonPath('meta.totals.tenants', 2)
            ->assertJsonPath('meta.totals.users', 3)
            ->assertJsonPath('data.0.slug', 'alfa')
            // Solo cuentan las cuentas activas
            ->assertJsonPath('data.0.users_count', 2)
            ->assertJsonPath('data.0.students_count', 0)
            ->assertJsonPath('data.0.sales_this_month', '0.00')
            ->assertJsonPath('data.1.last_login_at', null);
        $this->assertNotNull($this->withToken($token)->getJson($this->centralUrl('/platform/tenants?search=alf'))->assertJsonCount(1, 'data')->json('data.0.last_login_at'));

        $payload = ['organization_name' => 'Academia Gamma', 'subdomain' => 'Gamma', 'first_name' => 'Gala', 'last_name' => 'Gómez', 'email' => 'Gala@gamma.com', 'password' => 'temporal-123'];
        $this->withToken($token)->postJson($this->centralUrl('/platform/tenants'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'gamma')
            ->assertJsonPath('data.users_count', 1);
        $this->withToken($token)->postJson($this->centralUrl('/platform/tenants'), $payload)->assertStatus(422);
        $this->withToken($token)->postJson($this->centralUrl('/platform/tenants'), ['subdomain' => 'www'] + $payload)->assertStatus(422);

        // Su administrador entra con la contraseña temporal y debe cambiarla
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $admin = $this->postJson($this->tenantUrl('gamma', '/auth/login'), ['email' => 'gala@gamma.com', 'password' => 'temporal-123'])
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', true)
            ->json('data.token');
        $this->withToken($admin)->getJson($this->tenantUrl('gamma', '/users'))->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_support_access_is_read_only_short_lived_and_audited(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $token = $this->platformToken();

        $support = $this->withToken($token)->postJson($this->centralUrl("/platform/tenants/{$alfa->id}/support-session"))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'alfa')
            ->assertJsonPath('data.as_user', 'Ada Admin')
            ->json('data.token');

        // Ve lo que ve el administrador de esa organización, marcado como soporte
        $this->withToken($support)->getJson($this->tenantUrl('alfa', '/auth/me'))
            ->assertOk()
            ->assertJsonPath('data.support_mode', true)
            ->assertJsonPath('data.user.email', 'admin@alfa.com');
        $this->withToken($support)->getJson($this->tenantUrl('alfa', '/users'))->assertOk();

        // No puede cambiar nada, ni entrar a otra organización
        $this->withToken($support)->postJson($this->tenantUrl('alfa', '/users'), [])->assertForbidden()->assertJsonPath('code', 'SUPPORT_READ_ONLY');
        $this->withToken($support)->putJson($this->tenantUrl('alfa', '/auth/password'), [])->assertForbidden()->assertJsonPath('code', 'SUPPORT_READ_ONLY');
        $this->withToken($support)->getJson($this->tenantUrl('beta', '/auth/me'))->assertUnauthorized();

        // Una sesión normal no queda marcada como soporte
        $this->withToken($this->login('alfa', 'admin@alfa.com'))->getJson($this->tenantUrl('alfa', '/auth/me'))->assertJsonPath('data.support_mode', false);

        $event = AuditEvent::query()->where('tenant_id', $alfa->id)->where('action', 'platform.support_session_started')->firstOrFail();
        $this->assertSame('root@plataforma.test', $event->after['platform_admin']);

        // A los 30 minutos deja de servir aunque se haya estado usando
        $this->travel(20)->minutes();
        $this->withToken($support)->getJson($this->tenantUrl('alfa', '/users'))->assertOk();
        $this->travel(11)->minutes();
        $this->withToken($support)->getJson($this->tenantUrl('alfa', '/users'))->assertUnauthorized();

        // Un administrador de academia no puede abrir accesos de soporte
        $this->withToken($this->login('alfa', 'admin@alfa.com'))->postJson($this->centralUrl("/platform/tenants/{$alfa->id}/support-session"))->assertUnauthorized();
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
