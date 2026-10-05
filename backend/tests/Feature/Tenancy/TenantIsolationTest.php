<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TC-TEN-001: un usuario del tenant A no accede a datos del tenant B.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_from_one_tenant_is_rejected_on_another(): void
    {
        $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');

        $token = $this->login('alfa', 'admin@alfa.com');

        $this->withToken($token)->getJson($this->tenantUrl('alfa', '/auth/me'))->assertOk();

        $this->withToken($token)->getJson($this->tenantUrl('beta', '/auth/me'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->withToken($token)->getJson($this->tenantUrl('beta', '/users'))->assertUnauthorized();
    }

    public function test_same_email_can_exist_in_two_tenants_and_logs_into_its_own(): void
    {
        $alfa = $this->provisionTenant('alfa', 'persona@correo.com');
        $beta = $this->provisionTenant('beta', 'persona@correo.com');

        $this->postJson($this->tenantUrl('alfa', '/auth/login'), ['email' => 'persona@correo.com', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.tenant.id', $alfa->id);

        $this->postJson($this->tenantUrl('beta', '/auth/login'), ['email' => 'persona@correo.com', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.tenant.id', $beta->id);
    }

    public function test_user_listing_only_returns_own_tenant(): void
    {
        $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');

        $emails = $this->withToken($this->login('alfa', 'admin@alfa.com'))
            ->getJson($this->tenantUrl('alfa', '/users'))
            ->assertOk()
            ->json('data.*.email');

        $this->assertSame(['admin@alfa.com'], $emails);
    }

    public function test_eloquent_scope_denies_without_tenant_context(): void
    {
        $this->provisionTenant('alfa');
        $context = app(TenantContext::class);

        $context->clear();
        $this->assertSame(0, User::query()->count());

        $context->setSystem();
        $this->assertSame(1, User::query()->count());
    }

    public function test_row_level_security_isolates_raw_queries(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $beta = $this->provisionTenant('beta', 'admin@beta.com');
        $context = app(TenantContext::class);

        // Consultas sin Eloquent: solo la política de PostgreSQL filtra
        $context->runForTenant($alfa->id, function () use ($beta) {
            $this->assertSame(['admin@alfa.com'], DB::table('users')->pluck('email')->all());
            $this->assertSame(0, DB::table('users')->where('tenant_id', $beta->id)->count());
            $this->assertSame(0, DB::table('users')->where('tenant_id', $beta->id)->update(['status' => 'HACKED']));

            // Tampoco se puede escribir en otro tenant
            try {
                DB::transaction(fn () => DB::table('users')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $beta->id,
                    'email' => 'intruso@beta.com',
                    'first_name' => 'In',
                    'last_name' => 'Truso',
                ]));
                $this->fail('RLS debió rechazar la inserción en otro tenant.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('row-level security', $e->getMessage());
            }
        });

        $context->clear();
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('roles')->count());
        $this->assertSame(0, DB::table('audit_events')->count());

        $context->setSystem();
        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_audit_events_are_append_only(): void
    {
        $this->provisionTenant('alfa');
        $event = AuditEvent::query()->firstOrFail();

        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(fn () => $operation === 'update'
                    ? DB::table('audit_events')->where('id', $event->id)->update(['action' => 'alterado'])
                    : DB::table('audit_events')->where('id', $event->id)->delete());
                $this->fail("La auditoría debió rechazar {$operation}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('solo inserción', $e->getMessage());
            }
        }

        $this->assertSame('tenants.provisioned', $event->fresh()->action);
    }
}
