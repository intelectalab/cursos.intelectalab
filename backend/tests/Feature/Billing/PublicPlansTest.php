<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Models\Plan;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La portada de la plataforma muestra, sin iniciar sesión, los planes
 * vigentes (de menor a mayor precio) y la prueba gratuita.
 */
class PublicPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_lists_active_plans_and_the_free_trial(): void
    {
        app(TenantContext::class)->runAsSystem(function () {
            Plan::create(['name' => 'Hasta 200 alumnos', 'price' => 249, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 200]);
            Plan::create(['name' => 'Hasta 50 alumnos', 'price' => 99, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 50]);
            Plan::create(['name' => 'Plan antiguo', 'price' => 10, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 5, 'status' => Plan::STATUS_ARCHIVED]);
        });

        $this->getJson($this->centralUrl('/public/plans'))
            ->assertOk()
            ->assertJsonCount(2, 'data.plans')
            ->assertJsonPath('data.plans.0.name', 'Hasta 50 alumnos')
            ->assertJsonPath('data.plans.1.max_students', 200)
            ->assertJsonPath('data.trial.days', (int) config('billing.trial_days'))
            ->assertJsonPath('data.trial.max_students', (int) config('billing.trial_max_students'));

        // Es de la plataforma: dentro de una academia no existe
        $this->provisionTenant('acme', 'admin@acme.com');
        $this->getJson($this->tenantUrl('acme', '/public/plans'))->assertStatus(404);
    }
}
