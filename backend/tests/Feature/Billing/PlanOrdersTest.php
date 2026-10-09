<?php

namespace Tests\Feature\Billing;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Mi plan": la organización pide un plan, paga por fuera y la plataforma
 * confirma el pago, con lo que el plan queda vigente.
 */
class PlanOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $alfa;

    private string $platform;

    private string $admin;

    private string $basic;

    private string $big;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');

        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);
        $this->platform = $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->json('data.token');

        $plan = fn (array $data) => $this->withToken($this->platform)->postJson($this->centralUrl('/platform/plans'), $data + ['currency' => 'PEN', 'billing_months' => 1])->assertCreated()->json('data.id');
        $this->basic = $plan(['name' => 'Hasta 50 alumnos', 'price' => 99, 'max_students' => 50]);
        $this->big = $plan(['name' => 'Hasta 200 alumnos', 'price' => 249, 'max_students' => 200]);

        $this->admin = $this->login('alfa', 'admin@alfa.com');
    }

    private function alfa(string $path): string
    {
        return $this->tenantUrl('alfa', $path);
    }

    public function test_an_organization_orders_a_plan_and_the_platform_confirms_its_payment(): void
    {
        $this->withToken($this->platform)->putJson($this->centralUrl('/platform/billing-settings'), ['payment_instructions' => 'BCP 191-000000, a nombre de IntelectaLab.'])
            ->assertOk()->assertJsonPath('data.payment_instructions', 'BCP 191-000000, a nombre de IntelectaLab.');

        // La academia ve su situación, los planes que se ofrecen y cómo pagar
        $this->withToken($this->admin)->getJson($this->alfa('/subscription/plans'))
            ->assertOk()
            ->assertJsonPath('data.subscription.state', 'TRIAL')
            ->assertJsonCount(2, 'data.plans')
            ->assertJsonPath('data.plans.0.name', 'Hasta 50 alumnos')
            ->assertJsonPath('data.payment_instructions', 'BCP 191-000000, a nombre de IntelectaLab.')
            ->assertJsonPath('data.pending_order', null);

        $order = $this->withToken($this->admin)->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->basic])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.plan_name', 'Hasta 50 alumnos')
            ->assertJsonPath('data.amount', '99.00')
            ->json('data');

        // Una sola orden pendiente a la vez; pedir el plan todavía no lo activa
        $this->withToken($this->admin)->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->big])
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_ALREADY_PENDING');
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))->assertJsonPath('data.state', 'TRIAL');

        $this->withToken($this->admin)->putJson($this->alfa("/subscription/orders/{$order['id']}/payment"), ['payer_reference' => 'Operación 123456 del 8 de octubre'])
            ->assertOk()->assertJsonPath('data.payer_reference', 'Operación 123456 del 8 de octubre');

        // La plataforma la ve entre las pendientes, con la academia y lo que informó
        $this->withToken($this->platform)->getJson($this->centralUrl('/platform/plan-orders?status=PENDING'))
            ->assertOk()
            ->assertJsonPath('meta.counts.PENDING', 1)
            ->assertJsonPath('data.0.tenant.slug', 'alfa')
            ->assertJsonPath('data.0.payer_reference', 'Operación 123456 del 8 de octubre');

        $confirm = $this->centralUrl("/platform/plan-orders/{$order['id']}/confirm");
        $this->withToken($this->platform)->postJson($confirm, ['method' => 'BITCOIN'])->assertStatus(422);
        $this->withToken($this->platform)->postJson($confirm, ['method' => 'TRANSFER', 'reference' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.resolved_by', 'root@plataforma.test');

        // Confirmado el pago, el plan queda vigente por un mes, con su tope
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.plan.name', 'Hasta 50 alumnos')
            ->assertJsonPath('data.max_students', 50)
            ->assertJsonPath('data.days_left', (int) now()->startOfDay()->diffInDays(now()->addMonthNoOverflow()->startOfDay()));

        // No se confirma dos veces
        $this->withToken($this->platform)->postJson($confirm, ['method' => 'TRANSFER'])->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PENDING');

        // Renovar el mismo plan antes de que venza suma el mes al vencimiento actual
        $renewal = $this->withToken($this->admin)->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->basic])->assertCreated()->json('data.id');
        $this->withToken($this->platform)->postJson($this->centralUrl("/platform/plan-orders/{$renewal}/confirm"), ['method' => 'YAPE'])->assertOk();
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))
            ->assertJsonPath('data.days_left', (int) now()->startOfDay()->diffInDays(now()->addMonthsNoOverflow(2)->startOfDay()));

        $this->withToken($this->admin)->getJson($this->alfa('/subscription/plans'))
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.pending_order', null);

        $this->assertTrue(AuditEvent::query()->where('tenant_id', $this->alfa->id)->where('action', 'subscription.order_created')->exists());
        $this->assertSame(2, AuditEvent::query()->where('tenant_id', $this->alfa->id)->where('action', 'platform.plan_order_confirmed')->count());
    }

    public function test_orders_can_be_cancelled_and_are_private_to_each_organization(): void
    {
        $order = $this->withToken($this->admin)->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->basic])->assertCreated()->json('data.id');

        // Otra academia no ve ni toca esa orden
        $beta = $this->login('beta', 'admin@beta.com');
        $this->withToken($beta)->getJson($this->tenantUrl('beta', '/subscription/plans'))->assertJsonCount(0, 'data.orders');
        $this->withToken($beta)->postJson($this->tenantUrl('beta', "/subscription/orders/{$order}/cancel"))->assertNotFound();

        // La academia la cancela y puede pedir otro plan
        $this->withToken($this->admin)->postJson($this->alfa("/subscription/orders/{$order}/cancel"))->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->withToken($this->admin)->postJson($this->alfa("/subscription/orders/{$order}/cancel"))->assertStatus(409);
        $second = $this->withToken($this->admin)->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->big])->assertCreated()->json('data.id');

        // La plataforma también puede cancelarla, con motivo; una cancelada no se confirma
        $this->withToken($this->platform)->postJson($this->centralUrl("/platform/plan-orders/{$second}/cancel"), [])->assertStatus(422);
        $this->withToken($this->platform)->postJson($this->centralUrl("/platform/plan-orders/{$second}/cancel"), ['reason' => 'No llegó el pago.'])
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.notes', 'No llegó el pago.');
        $this->withToken($this->platform)->postJson($this->centralUrl("/platform/plan-orders/{$second}/confirm"), ['method' => 'TRANSFER'])->assertStatus(409);
        $this->withToken($this->admin)->getJson($this->alfa('/subscription'))->assertJsonPath('data.state', 'TRIAL');

        // Un plan archivado no se puede pedir
        $this->withToken($this->platform)->putJson($this->centralUrl("/platform/plans/{$this->big}"), [
            'name' => 'Hasta 200 alumnos', 'price' => 249, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 200, 'status' => 'ARCHIVED',
        ])->assertOk();
        $this->withToken($this->admin)->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->big])->assertStatus(422);
        $this->withToken($this->admin)->getJson($this->alfa('/subscription/plans'))->assertJsonCount(1, 'data.plans');

        // Pedir un plan es del administrador de la academia; nadie de una academia entra a la consola
        $student = $this->createUser($this->alfa, RoleCatalog::STUDENT);
        $this->withToken($this->login('alfa', $student->email))->postJson($this->alfa('/subscription/orders'), ['plan_id' => $this->basic])->assertForbidden();
        $this->withToken($this->admin)->getJson($this->centralUrl('/platform/plan-orders'))->assertUnauthorized();
    }
}
