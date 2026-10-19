<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel de la consola: academias, ingresos por planes, uso y alertas en un
 * periodo, y su descarga en Excel.
 */
class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function platformToken(): string
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);

        return $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->assertOk()->json('data.token');
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $token, string $section, array $period = []): array
    {
        return $this->withToken($token)->getJson($this->centralUrl("/platform/dashboard/{$section}").($period ? '?'.http_build_query($period) : ''))->assertOk()->json('data');
    }

    /**
     * @param  array<string, mixed>  $panel
     */
    private function card(array $panel, string $label): array
    {
        return collect($panel['cards'])->firstWhere('label', $label) ?? $this->fail("No está la tarjeta «{$label}»");
    }

    /**
     * @param  array<string, mixed>  $panel
     * @return array<string, float|int>
     */
    private function chart(array $panel, string $title): array
    {
        $chart = collect($panel['charts'])->firstWhere('title', $title) ?? $this->fail("No está el gráfico «{$title}»");

        return collect($chart['items'])->pluck('value', 'label')->all();
    }

    public function test_the_panel_summarizes_academies_income_usage_and_alerts(): void
    {
        $acme = $this->provisionTenant('acme', 'admin@acme.com');
        $beta = $this->provisionTenant('beta', 'admin@beta.com');
        $this->provisionTenant('gamma', 'admin@gamma.com');
        $token = $this->platformToken();

        // Acme paga un plan de 50 alumnos; Beta está en su prueba, que vence en 3 días; Gamma, suspendida
        app(TenantContext::class)->runAsSystem(function () use ($acme, $beta) {
            $plan = Plan::create(['name' => 'Hasta 50 alumnos', 'price' => 99, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 50]);
            TenantSubscription::query()->where('tenant_id', $acme->id)->update(['plan_id' => $plan->id, 'status' => TenantSubscription::STATUS_ACTIVE, 'current_period_ends_at' => now()->addMonth(), 'max_students' => 50]);
            TenantSubscription::query()->where('tenant_id', $beta->id)->update(['trial_ends_at' => now()->addDays(3)]);
            PlanOrder::create([
                'tenant_id' => $acme->id, 'number' => 1, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'billing_months' => 1, 'max_students' => 50,
                'amount' => 99, 'currency' => 'PEN', 'status' => PlanOrder::STATUS_PAID, 'payment_method' => 'TRANSFER', 'paid_at' => now(),
            ]);
            PlanOrder::create([
                'tenant_id' => $beta->id, 'number' => 2, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'billing_months' => 1, 'max_students' => 50,
                'amount' => 99, 'currency' => 'PEN', 'status' => PlanOrder::STATUS_PENDING, 'payer_reference' => 'Operación 123', 'expires_at' => now()->addWeek(),
            ]);
            Tenant::query()->whereKey(Tenant::query()->where('slug', 'gamma')->value('id'))->update(['status' => Tenant::STATUS_SUSPENDED]);
        });

        $academies = $this->section($token, 'academies');
        $this->assertSame(3, $this->card($academies, 'Academias')['value']);
        $this->assertSame(3, $this->card($academies, 'Nuevas en el periodo')['value']);
        $this->assertSame(1, $this->card($academies, 'Requieren atención')['value']);
        $this->assertSame(['Con plan pagado' => 1, 'En prueba gratuita' => 1, 'Suspendida' => 1], $this->chart($academies, 'Academias por situación'));
        $this->assertCount(3, $academies['table']['rows']);

        $income = $this->section($token, 'income');
        $this->assertEquals(99, $this->card($income, 'Cobrado en el periodo')['value']);
        $this->assertEquals(0, $this->card($income, 'Cobrado en el periodo')['previous']);
        $this->assertEquals(99, $this->card($income, 'Ingreso mensual de los planes vigentes')['value']);
        $this->assertEquals(99, $this->card($income, 'Por confirmar')['value']);
        $this->assertEquals(['Transferencia' => 99], $this->chart($income, 'Cobrado por medio de pago en el periodo'));
        $this->assertSame('P-0001', $income['table']['rows'][0]['code']);

        $this->assertSame(0, $this->card($this->section($token, 'usage'), 'Alumnos activos')['value']);

        $alerts = $this->section($token, 'alerts');
        $this->assertSame(1, $this->card($alerts, 'Pagos informados por confirmar')['value']);
        $this->assertSame(1, $this->card($alerts, 'Por vencer en 7 días')['value']);
        $this->assertEqualsCanonicalizing(['Pago de plan informado', 'Por vencer'], array_column($alerts['table']['rows'], 'reason'));

        // Un periodo pasado no tiene ingresos; el anterior a este es el que se compara
        $past = $this->section($token, 'income', ['from' => '2024-01-01', 'to' => '2024-01-31']);
        $this->assertEquals(0, $this->card($past, 'Cobrado en el periodo')['value']);
        $this->withToken($token)->getJson($this->centralUrl('/platform/dashboard/income?from=2024-02-01&to=2024-01-01'))->assertStatus(422);
        $this->withToken($token)->getJson($this->centralUrl('/platform/dashboard/otra'))->assertNotFound();
    }

    public function test_the_panel_downloads_as_excel_and_only_for_the_platform(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->platformToken();

        $response = $this->withToken($token)->get($this->centralUrl('/platform/dashboard/export?from=2026-01-01&to=2026-01-31'))->assertOk();
        $this->assertStringContainsString('panel-plataforma-2026-01-01-al-2026-01-31.xlsx', (string) $response->headers->get('Content-Disposition'));

        // Sin sesión de plataforma, nada
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->centralUrl('/platform/dashboard/academies'))->assertUnauthorized();
        // La sesión de una academia no sirve en la consola
        $academy = $this->login('acme', 'admin@acme.com');
        $this->app['auth']->forgetGuards();
        $this->withToken($academy)->getJson($this->centralUrl('/platform/dashboard/academies'))->assertUnauthorized();
    }
}
