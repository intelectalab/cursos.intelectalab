<?php

namespace Tests\Feature\Billing;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Planes de la plataforma, prueba gratuita de cada organización nueva y
 * asignación de un plan desde la consola.
 */
class PlansAndTrialTest extends TestCase
{
    use RefreshDatabase;

    private function platformToken(): string
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);

        return $this->postJson($this->centralUrl('/platform/auth/login'), [
            'email' => 'root@plataforma.test', 'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');
    }

    public function test_a_new_organization_starts_on_a_free_trial_and_sees_its_own_plan(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $admin = $this->login('alfa', 'admin@alfa.com');

        $this->withToken($admin)->getJson($this->tenantUrl('alfa', '/subscription'))
            ->assertOk()
            ->assertJsonPath('data.state', 'TRIAL')
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.days_left', 14)
            ->assertJsonPath('data.max_students', 10)
            ->assertJsonPath('data.students', 0)
            ->assertJsonPath('data.seats_left', 10);

        // Cuenta como alumno quien tiene una matrícula activa, no cada cuenta creada
        $course = $this->withToken($admin)->postJson($this->tenantUrl('alfa', '/courses'), ['title' => 'Excel', 'language' => 'es'])->json('data.id');
        $section = $this->withToken($admin)->postJson($this->tenantUrl('alfa', "/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $this->withToken($admin)->postJson($this->tenantUrl('alfa', "/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Hola', 'body' => 'Hola.'])->assertCreated();
        $this->withToken($admin)->postJson($this->tenantUrl('alfa', "/courses/{$course}/publish"))->assertOk();
        $enrolled = $this->createUser($alfa, RoleCatalog::STUDENT);
        $this->createUser($alfa, RoleCatalog::STUDENT);
        $this->withToken($admin)->postJson($this->tenantUrl('alfa', "/courses/{$course}/enrollments"), ['student_ids' => [$enrolled->id]])->assertCreated();

        $this->withToken($admin)->getJson($this->tenantUrl('alfa', '/subscription'))
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.seats_left', 9);

        // Pasados los días, la prueba figura vencida
        $this->travel(15)->days();
        $this->withToken($this->login('alfa', 'admin@alfa.com'))->getJson($this->tenantUrl('alfa', '/subscription'))
            ->assertJsonPath('data.state', 'TRIAL_EXPIRED')
            ->assertJsonPath('data.days_left', 0);

        // Un alumno no consulta el plan de la academia
        $this->withToken($this->login('alfa', $enrolled->email))->getJson($this->tenantUrl('alfa', '/subscription'))->assertForbidden();
    }

    public function test_platform_admin_manages_plans_and_assigns_one_to_an_organization(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $token = $this->platformToken();
        $plans = $this->centralUrl('/platform/plans');

        $this->withToken($token)->getJson($plans)
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.trial_days', 14)
            ->assertJsonPath('meta.trial_max_students', 10);

        $this->withToken($token)->postJson($plans, ['name' => 'Malo', 'price' => -1, 'currency' => 'PEN', 'billing_months' => 3])->assertStatus(422);
        $basic = $this->withToken($token)->postJson($plans, [
            'name' => 'Hasta 50 alumnos', 'price' => 99, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 50,
        ])->assertCreated()->assertJsonPath('data.max_students', 50)->json('data.id');
        $annual = $this->withToken($token)->postJson($plans, [
            'name' => 'Hasta 200 alumnos, anual', 'price' => 2490, 'currency' => 'PEN', 'billing_months' => 12, 'max_students' => 200,
        ])->assertCreated()->json('data.id');

        $profile = $this->centralUrl("/platform/tenants/{$alfa->id}");
        $this->withToken($token)->getJson($profile)->assertJsonPath('data.subscription.state', 'TRIAL');

        // Extiende la prueba mientras la academia decide
        $this->withToken($token)->putJson("{$profile}/subscription/trial", ['trial_ends_at' => now()->addDays(30)->toDateString()])
            ->assertOk()->assertJsonPath('data.state', 'TRIAL')->assertJsonPath('data.days_left', 30);

        // Asigna el plan mensual: un periodo desde hoy, con el tope del plan
        $this->withToken($token)->putJson("{$profile}/subscription", ['plan_id' => $basic])
            ->assertOk()
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.plan.name', 'Hasta 50 alumnos')
            ->assertJsonPath('data.max_students', 50);
        // Ya no está en prueba: no hay prueba que extender
        $this->withToken($token)->putJson("{$profile}/subscription/trial", ['trial_ends_at' => now()->addDays(30)->toDateString()])->assertNotFound();

        $this->withToken($token)->getJson($plans)
            ->assertJsonPath('data.0.name', 'Hasta 50 alumnos')
            ->assertJsonPath('data.0.tenants_count', 1);
        $this->withToken($token)->getJson($this->centralUrl('/platform/tenants'))
            ->assertJsonPath('data.0.subscription.plan.name', 'Hasta 50 alumnos');
        $this->withToken($this->login('alfa', 'admin@alfa.com'))->getJson($this->tenantUrl('alfa', '/subscription'))
            ->assertJsonPath('data.state', 'ACTIVE')->assertJsonPath('data.plan.name', 'Hasta 50 alumnos');

        // Con fecha propia, y vencido el periodo figura vencido
        $this->withToken($token)->putJson("{$profile}/subscription", ['plan_id' => $annual, 'ends_at' => now()->addDays(10)->toDateString()])
            ->assertOk()->assertJsonPath('data.max_students', 200);
        $this->withToken($token)->putJson("{$profile}/subscription", ['plan_id' => $annual, 'ends_at' => now()->subDay()->toDateString()])->assertStatus(422);

        // Un plan archivado no se asigna, pero quien lo tiene lo conserva
        $this->withToken($token)->putJson("{$plans}/{$annual}", [
            'name' => 'Hasta 200 alumnos, anual', 'price' => 2490, 'currency' => 'PEN', 'billing_months' => 12, 'max_students' => 200, 'status' => 'ARCHIVED',
        ])->assertOk()->assertJsonPath('data.status', 'ARCHIVED');
        $this->withToken($token)->putJson("{$profile}/subscription", ['plan_id' => $annual])->assertStatus(422);
        $this->withToken($token)->getJson($profile)->assertJsonPath('data.subscription.plan.name', 'Hasta 200 alumnos, anual');

        // Sin plan: la academia queda sin límites ni vencimiento
        $this->withToken($token)->putJson("{$profile}/subscription", ['plan_id' => null])
            ->assertOk()
            ->assertJsonPath('data.state', 'UNLIMITED')
            ->assertJsonPath('data.max_students', null)
            ->assertJsonPath('data.ends_at', null);

        // Cada cambio queda en la auditoría de la academia y en el historial de su ficha
        $this->assertSame(4, AuditEvent::query()->where('tenant_id', $alfa->id)->where('action', 'platform.subscription_changed')->count());
        $this->withToken($token)->getJson($profile)->assertJsonPath('data.history.0.action', 'platform.subscription_changed');

        // Un administrador de academia no llega a los planes de la plataforma
        $this->withToken($this->login('alfa', 'admin@alfa.com'))->getJson($plans)->assertUnauthorized();
    }
}
