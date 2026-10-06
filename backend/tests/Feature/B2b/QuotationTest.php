<?php

namespace Tests\Feature\B2b;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\B2b\Domain\Models\CorporateAccount;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S8 entrega 2: cotización corporativa → aceptación → venta (FP-04, RN-011).
 */
class QuotationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publishedCourse(array $attributes = []): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), $attributes + ['title' => 'Power BI desde cero', 'language' => 'es', 'price' => 150])
            ->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.'])
            ->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    private function company(string $name = 'Minera Andes S.A.'): string
    {
        return $this->withToken($this->admin)->postJson($this->api('/crm/companies'), ['name' => $name])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function quotation(string $company, string $course, array $attributes = []): string
    {
        return $this->withToken($this->admin)->postJson($this->api('/quotations'), $attributes + [
            'company_id' => $company,
            'items' => [['course_id' => $course, 'quantity' => 40, 'unit_price' => 120]],
        ])->assertCreated()->json('data.id');
    }

    private function send(string $quotation): void
    {
        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/send"))->assertOk();
    }

    public function test_an_accepted_quotation_becomes_a_company_sale_with_the_quoted_prices(): void
    {
        $course = $this->publishedCourse();
        $company = $this->company();
        $opportunity = $this->withToken($this->admin)->postJson($this->api('/crm/opportunities'), ['company_id' => $company, 'title' => 'Capacitación en Power BI'])->json('data.id');

        $quotation = $this->withToken($this->admin)->postJson($this->api('/quotations'), [
            'company_id' => $company,
            'opportunity_id' => $opportunity,
            'items' => [['course_id' => $course, 'quantity' => 40, 'unit_price' => 120]],
        ])->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.total_amount', '4800.00')
            ->assertJsonPath('data.items.0.list_price', '150.00')
            ->assertJsonPath('data.valid_until', now()->addDays(15)->toDateString())
            ->json('data.id');

        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/send"))
            ->assertOk()
            ->assertJsonPath('data.status', 'SENT')
            ->assertJsonCount(1, 'data.versions');

        // Al enviarla, la oportunidad avanza sola
        $this->withToken($this->admin)->getJson($this->api("/crm/opportunities/{$opportunity}"))->assertJsonPath('data.stage', 'PROPOSAL');

        // El precio de lista cambia después: la venta respeta lo cotizado (RN-011)
        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}"), ['title' => 'Power BI desde cero', 'language' => 'es', 'price' => 300])->assertOk();

        $accepted = $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/accept"), ['note' => 'Aceptó por correo'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED')
            ->assertJsonPath('data.accepted_via', 'STAFF')
            ->assertJsonPath('data.order.status', 'PENDING')
            ->json('data');

        $this->withToken($this->admin)->getJson($this->api("/orders/{$accepted['order']['id']}"))
            ->assertOk()
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.company.id', $company)
            ->assertJsonPath('data.total_amount', '4800.00')
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.items.0.quantity', 40)
            ->assertJsonPath('data.items.0.unit_price', '120.00');

        $this->withToken($this->admin)->getJson($this->api('/orders?search=minera'))->assertJsonCount(1, 'data');
        $this->withToken($this->admin)->getJson($this->api("/crm/companies/{$company}"))->assertJsonPath('data.status', 'CUSTOMER');
        $this->withToken($this->admin)->getJson($this->api("/crm/opportunities/{$opportunity}"))->assertJsonPath('data.stage', 'WON');

        app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($company) {
            $this->assertTrue(CorporateAccount::query()->where('company_id', $company)->exists());
            $this->assertSame(1, AuditEvent::query()->where('action', 'quotations.accepted')->count());
        });

        // Aceptar dos veces no genera otra venta
        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/accept"))
            ->assertStatus(409)->assertJsonPath('code', 'QUOTATION_INVALID_STATE');
        $this->withToken($this->admin)->getJson($this->api('/orders'))->assertJsonCount(1, 'data');
    }

    public function test_paying_a_company_order_enrolls_nobody(): void
    {
        $quotation = $this->quotation($this->company(), $this->publishedCourse());
        $this->send($quotation);
        $order = $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/accept"))->json('data.order.id');

        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER', 'reference' => 'OP-778'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.items.0.enrolled', false);

        $this->assertSame(0, app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Enrollment::query()->count()));
    }

    public function test_changing_a_sent_quotation_opens_a_new_version_and_keeps_the_old_one(): void
    {
        $course = $this->publishedCourse();
        $quotation = $this->quotation($this->company(), $course);
        $this->send($quotation);

        $edit = [
            'valid_until' => now()->addDays(30)->toDateString(),
            'items' => [['course_id' => $course, 'quantity' => 40, 'unit_price' => 100]],
        ];

        // Enviada no se edita directamente
        $this->withToken($this->admin)->putJson($this->api("/quotations/{$quotation}"), $edit)
            ->assertStatus(409)->assertJsonPath('code', 'QUOTATION_INVALID_STATE');

        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/revise"))
            ->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.version', 2);

        // Mientras la versión nueva está en borrador, no hay nada que aceptar
        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/accept"))->assertStatus(409);

        $this->withToken($this->admin)->putJson($this->api("/quotations/{$quotation}"), $edit)
            ->assertOk()->assertJsonPath('data.total_amount', '4000.00');

        $versions = $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/send"))->assertOk()->json('data.versions');

        $this->assertSame([[2, '4000.00'], [1, '4800.00']], array_map(fn (array $v) => [$v['version'], $v['total_amount']], $versions));
        $this->assertSame('120.00', $versions[1]['items'][0]['unit_price']);

        $order = $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/accept"))->assertOk()->json('data.order.id');
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}"))->assertJsonPath('data.total_amount', '4000.00');

        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/revise"))->assertStatus(409);
    }

    public function test_an_expired_quotation_cannot_be_accepted_until_a_new_version_is_sent(): void
    {
        $quotation = $this->quotation($this->company(), $this->publishedCourse());
        $this->send($quotation);

        $this->travel(16)->days();

        $this->withToken($this->login('acme', 'admin@acme.com'))->getJson($this->api("/quotations/{$quotation}"))->assertJsonPath('data.status', 'EXPIRED');
        $admin = $this->login('acme', 'admin@acme.com');

        $this->withToken($admin)->getJson($this->api('/quotations?status=EXPIRED'))->assertJsonCount(1, 'data');
        $this->withToken($admin)->getJson($this->api('/quotations?status=SENT'))->assertJsonCount(0, 'data');
        $this->withToken($admin)->postJson($this->api("/quotations/{$quotation}/accept"))
            ->assertStatus(409)->assertJsonPath('code', 'QUOTATION_EXPIRED');

        // La versión nueva nace con vigencia otra vez
        $this->withToken($admin)->postJson($this->api("/quotations/{$quotation}/revise"))
            ->assertOk()->assertJsonPath('data.valid_until', now()->addDays(15)->toDateString());
        $this->withToken($admin)->postJson($this->api("/quotations/{$quotation}/send"))->assertOk();
        $this->withToken($admin)->postJson($this->api("/quotations/{$quotation}/accept"))->assertOk();
    }

    public function test_quotations_are_validated_before_sending_and_rejections_need_a_reason(): void
    {
        $course = $this->publishedCourse();
        $draft = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'Borrador', 'language' => 'es'])->json('data.id');
        $company = $this->company();
        $other = $this->company('Textiles del Sur');
        $foreignContact = $this->withToken($this->admin)->postJson($this->api("/crm/companies/{$other}/contacts"), ['first_name' => 'Mario'])->json('data.contacts.0.id');

        $create = fn (array $data) => $this->withToken($this->admin)->postJson($this->api('/quotations'), $data + ['company_id' => $company, 'items' => []]);

        $create(['items' => [['course_id' => $draft, 'quantity' => 5, 'unit_price' => 10]]])->assertStatus(422);
        $create(['items' => [['course_id' => $course, 'quantity' => 0, 'unit_price' => 10]]])->assertStatus(422);
        $create(['items' => [['course_id' => $course, 'quantity' => 1, 'unit_price' => 1], ['course_id' => $course, 'quantity' => 2, 'unit_price' => 1]]])->assertStatus(422);
        $create(['contact_id' => $foreignContact])->assertStatus(422);
        $create(['valid_until' => now()->subDay()->toDateString()])->assertStatus(422);

        // Sin cursos se guarda como borrador, pero no se envía
        $empty = $create([])->assertCreated()->json('data.id');
        $this->withToken($this->admin)->postJson($this->api("/quotations/{$empty}/send"))
            ->assertStatus(409)->assertJsonPath('code', 'QUOTATION_INCOMPLETE');

        $quotation = $this->quotation($company, $course);
        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/reject"), ['reason' => 'Muy caro'])->assertStatus(409);
        $this->send($quotation);
        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/reject"), [])->assertStatus(422);
        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/reject"), ['reason' => 'Muy caro'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.reject_reason', 'Muy caro');

        // Rechazada no genera venta, pero admite una contraoferta
        $this->withToken($this->admin)->getJson($this->api('/orders'))->assertJsonCount(0, 'data');
        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/revise"))->assertOk()->assertJsonPath('data.reject_reason', null);

        $options = $this->withToken($this->admin)->getJson($this->api('/quotations/options'))->assertOk()->json('data');
        $this->assertSame(['Power BI desde cero'], array_column($options['courses'], 'title'));
        $this->assertSame('PEN', $options['currency']);
    }

    public function test_quotations_respect_roles_and_organizations(): void
    {
        $course = $this->publishedCourse();
        $company = $this->company();
        $quotation = $this->quotation($company, $course);

        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::SUPPORT, ['email' => 'sol@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::B2B, ['email' => 'beto@acme.com']);

        foreach (['ana@acme.com', 'sol@acme.com'] as $email) {
            $token = $this->login('acme', $email);
            $this->withToken($token)->getJson($this->api('/quotations'))->assertStatus(403);
            $this->withToken($token)->postJson($this->api("/quotations/{$quotation}/accept"))->assertStatus(403);
        }

        // El rol B2B cotiza y ve las empresas, aunque no lleva el CRM
        $b2b = $this->login('acme', 'beto@acme.com');
        $this->withToken($b2b)->getJson($this->api('/crm/companies'))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($b2b)->getJson($this->api('/crm/leads'))->assertStatus(403);
        $this->withToken($b2b)->postJson($this->api('/quotations'), ['company_id' => $company, 'items' => []])
            ->assertCreated()->assertJsonPath('data.number', 2);

        $this->provisionTenant('globex', 'admin@globex.com');
        $globex = $this->login('globex', 'admin@globex.com');
        $url = fn (string $path) => $this->tenantUrl('globex', $path);

        $this->withToken($globex)->getJson($url('/quotations'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($globex)->getJson($url("/quotations/{$quotation}"))->assertStatus(404);
        $this->withToken($globex)->postJson($url("/quotations/{$quotation}/send"))->assertStatus(404);
        $this->withToken($globex)->postJson($url('/quotations'), ['company_id' => $company, 'items' => []])->assertStatus(422);
    }
}
