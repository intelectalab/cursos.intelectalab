<?php

namespace Tests\Feature\Crm;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S8 entrega 1: CRM. Prospecto → empresa y contacto → oportunidad → seguimiento (FP-21).
 */
class CrmTest extends TestCase
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
        return $this->tenantUrl('acme', "/crm{$path}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(array $attributes = []): string
    {
        return $this->withToken($this->admin)->postJson($this->api('/leads'), $attributes + [
            'first_name' => 'Luis', 'last_name' => 'Rojas', 'company_name' => 'Minera Andes', 'position' => 'Jefe de RR. HH.',
            'email' => 'luis@mineraandes.pe', 'source' => 'REFERRAL', 'interest' => '40 licencias de Power BI',
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function company(array $attributes = []): string
    {
        return $this->withToken($this->admin)->postJson($this->api('/companies'), $attributes + ['name' => 'Minera Andes S.A.'])
            ->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function opportunity(string $company, array $attributes = []): string
    {
        return $this->withToken($this->admin)->postJson($this->api('/opportunities'), $attributes + [
            'company_id' => $company, 'title' => 'Capacitación en Power BI', 'estimated_amount' => 6000,
        ])->assertCreated()->json('data.id');
    }

    private function auditCount(string $action): int
    {
        return app(TenantContext::class)->runForTenant($this->tenant->id, fn () => AuditEvent::query()->where('action', $action)->count());
    }

    public function test_a_lead_becomes_a_company_a_contact_and_an_opportunity(): void
    {
        $lead = $this->lead();

        $converted = $this->withToken($this->admin)->postJson($this->api("/leads/{$lead}/convert"), [
            'company' => ['name' => 'Minera Andes S.A.', 'tax_id' => '20100123456'],
            'opportunity' => ['title' => 'Capacitación en Power BI', 'estimated_amount' => 6000],
        ])->assertOk()
            ->assertJsonPath('data.status', 'CONVERTED')
            ->assertJsonPath('data.company.name', 'Minera Andes S.A.')
            ->json('data');

        $this->withToken($this->admin)->getJson($this->api("/companies/{$converted['company']['id']}"))
            ->assertOk()
            ->assertJsonPath('data.status', 'PROSPECT')
            ->assertJsonPath('data.contacts.0.name', 'Luis Rojas')
            ->assertJsonPath('data.contacts.0.is_primary', true)
            ->assertJsonPath('data.opportunities.0.id', $converted['opportunity_id'])
            ->assertJsonPath('data.open_opportunities_count', 1);

        $this->withToken($this->admin)->getJson($this->api("/opportunities/{$converted['opportunity_id']}"))
            ->assertOk()
            ->assertJsonPath('data.stage', 'NEW')
            ->assertJsonPath('data.contact.name', 'Luis Rojas')
            ->assertJsonPath('data.notes', '40 licencias de Power BI')
            ->assertJsonPath('data.owner.name', 'Ada Admin');

        $this->assertSame(1, $this->auditCount('leads.converted'));

        // Un prospecto convertido ya no se convierte ni se edita otra vez
        $this->withToken($this->admin)->postJson($this->api("/leads/{$lead}/convert"), ['company' => ['name' => 'Otra']])
            ->assertStatus(409)->assertJsonPath('code', 'LEAD_NOT_OPEN');
        $this->withToken($this->admin)->postJson($this->api("/leads/{$lead}/discard"), ['reason' => 'Duplicado'])
            ->assertStatus(409);
    }

    public function test_a_lead_can_join_an_existing_company_without_an_opportunity(): void
    {
        $company = $this->company(['tax_id' => '20100123456']);
        $lead = $this->lead();

        // El RUC de una empresa que ya existe no crea otra
        $this->withToken($this->admin)->postJson($this->api("/leads/{$lead}/convert"), [
            'company' => ['name' => 'Minera Andes', 'tax_id' => '20100123456'],
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->withToken($this->admin)->postJson($this->api("/leads/{$lead}/convert"), ['company_id' => $company])
            ->assertOk()
            ->assertJsonPath('data.company.id', $company)
            ->assertJsonPath('data.opportunity_id', null);

        $this->withToken($this->admin)->getJson($this->api("/companies/{$company}"))
            ->assertJsonCount(1, 'data.contacts')
            ->assertJsonCount(0, 'data.opportunities');
    }

    public function test_leads_need_a_way_to_be_contacted_and_a_reason_to_be_discarded(): void
    {
        $this->withToken($this->admin)->postJson($this->api('/leads'), ['first_name' => 'Luis', 'source' => 'WEB'])
            ->assertStatus(422);

        $lead = $this->lead(['email' => null, 'phone' => '999 888 777']);

        $this->withToken($this->admin)->putJson($this->api("/leads/{$lead}"), [
            'first_name' => 'Luis', 'phone' => '999 888 777', 'source' => 'CALL', 'status' => 'CONTACTED',
        ])->assertOk()->assertJsonPath('data.status', 'CONTACTED');

        $this->withToken($this->admin)->postJson($this->api("/leads/{$lead}/discard"), [])->assertStatus(422);
        $this->withToken($this->admin)->postJson($this->api("/leads/{$lead}/discard"), ['reason' => 'Sin presupuesto'])
            ->assertOk()->assertJsonPath('data.status', 'DISCARDED');

        $this->withToken($this->admin)->putJson($this->api("/leads/{$lead}"), ['first_name' => 'Luis', 'phone' => '1', 'source' => 'CALL'])
            ->assertStatus(409);

        $this->withToken($this->admin)->getJson($this->api('/leads?status=OPEN'))->assertJsonCount(0, 'data');
        $this->withToken($this->admin)->getJson($this->api('/leads?search=minera'))->assertJsonCount(1, 'data');
    }

    public function test_companies_are_unique_by_tax_id_and_keep_one_primary_contact(): void
    {
        $company = $this->company(['tax_id' => '20100123456']);

        $this->withToken($this->admin)->postJson($this->api('/companies'), ['name' => 'Otra', 'tax_id' => '20100123456'])
            ->assertStatus(422);
        // Editar la propia empresa no choca con su RUC
        $this->withToken($this->admin)->putJson($this->api("/companies/{$company}"), ['name' => 'Minera Andes', 'tax_id' => '20100123456', 'city' => 'Arequipa'])
            ->assertOk()->assertJsonPath('data.city', 'Arequipa');

        $first = $this->withToken($this->admin)->postJson($this->api("/companies/{$company}/contacts"), ['first_name' => 'Luis'])
            ->assertCreated()->assertJsonPath('data.contacts.0.is_primary', true)->json('data.contacts.0.id');

        $contacts = $this->withToken($this->admin)->postJson($this->api("/companies/{$company}/contacts"), ['first_name' => 'Carla', 'is_primary' => true])
            ->assertCreated()->json('data.contacts');

        $this->assertSame(['Carla'], collect($contacts)->where('is_primary', true)->pluck('first_name')->all());

        $this->withToken($this->admin)->deleteJson($this->api("/contacts/{$first}"))
            ->assertOk()->assertJsonCount(1, 'data.contacts');

        $this->withToken($this->admin)->getJson($this->api('/companies?search=carla'))->assertJsonCount(1, 'data');
        $this->assertSame(1, $this->auditCount('companies.updated'));
    }

    public function test_an_opportunity_moves_through_the_funnel_and_keeps_its_history(): void
    {
        $company = $this->company();
        $opportunity = $this->opportunity($company);

        $this->withToken($this->admin)->postJson($this->api("/opportunities/{$opportunity}/stage"), ['stage' => 'PROPOSAL'])
            ->assertOk()->assertJsonPath('data.stage', 'PROPOSAL')->assertJsonPath('data.closed_at', null);

        $this->withToken($this->admin)->postJson($this->api("/opportunities/{$opportunity}/activities"), [
            'type' => 'CALL', 'notes' => 'Pidió la propuesta para 40 personas.',
        ])->assertCreated()->assertJsonCount(2, 'data.activities');

        // Perderla exige el motivo
        $this->withToken($this->admin)->postJson($this->api("/opportunities/{$opportunity}/stage"), ['stage' => 'LOST'])
            ->assertStatus(422);
        $lost = $this->withToken($this->admin)->postJson($this->api("/opportunities/{$opportunity}/stage"), ['stage' => 'LOST', 'lost_reason' => 'Eligió a otro proveedor'])
            ->assertOk()->json('data');

        $this->assertSame('Eligió a otro proveedor', $lost['lost_reason']);
        $this->assertNotNull($lost['closed_at']);
        $this->assertSame(['STAGE', 'CALL', 'STAGE'], array_column($lost['activities'], 'type'));

        // Reabrirla limpia el cierre
        $this->withToken($this->admin)->postJson($this->api("/opportunities/{$opportunity}/stage"), ['stage' => 'NEGOTIATION'])
            ->assertOk()->assertJsonPath('data.lost_reason', null)->assertJsonPath('data.closed_at', null);

        $this->opportunity($company, ['title' => 'Excel para finanzas', 'estimated_amount' => 1500.50]);

        $board = collect($this->withToken($this->admin)->getJson($this->api('/opportunities/board'))->assertOk()->json('data'))->keyBy('stage');

        $this->assertSame(['NEW', 'CONTACTED', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'], $board->keys()->all());
        $this->assertSame([1, '1500.50'], [$board['NEW']['count'], $board['NEW']['amount']]);
        $this->assertSame([1, '6000.00'], [$board['NEGOTIATION']['count'], $board['NEGOTIATION']['amount']]);
        $this->assertSame('Capacitación en Power BI', $board['NEGOTIATION']['items'][0]['title']);
        $this->assertSame(3, $this->auditCount('opportunities.stage_changed'));
    }

    public function test_an_opportunity_only_accepts_contacts_of_its_company_and_sales_staff_as_owner(): void
    {
        $company = $this->company();
        $other = $this->company(['name' => 'Textiles del Sur']);
        $foreign = $this->withToken($this->admin)->postJson($this->api("/companies/{$other}/contacts"), ['first_name' => 'Mario'])->json('data.contacts.0.id');
        $student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $seller = $this->createUser($this->tenant, RoleCatalog::SALES, ['email' => 'vera@acme.com', 'first_name' => 'Vera', 'last_name' => 'Ventas']);

        $this->withToken($this->admin)->postJson($this->api('/opportunities'), ['company_id' => $company, 'contact_id' => $foreign, 'title' => 'X'])
            ->assertStatus(422);
        $this->withToken($this->admin)->postJson($this->api('/opportunities'), ['company_id' => $company, 'title' => 'X', 'owner_id' => $student->id])
            ->assertStatus(422);

        $this->withToken($this->admin)->postJson($this->api('/opportunities'), ['company_id' => $company, 'title' => 'X', 'owner_id' => $seller->id])
            ->assertCreated()->assertJsonPath('data.owner.name', 'Vera Ventas');

        $owners = $this->withToken($this->admin)->getJson($this->api('/owners'))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Ada Admin', 'Vera Ventas'], array_column($owners, 'name'));
    }

    public function test_only_the_sales_team_reaches_the_crm(): void
    {
        $company = $this->company();
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::SUPPORT, ['email' => 'sol@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::SALES, ['email' => 'vera@acme.com']);

        foreach (['ana@acme.com', 'sol@acme.com'] as $email) {
            $token = $this->login('acme', $email);
            $this->withToken($token)->getJson($this->api('/companies'))->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
            $this->withToken($token)->postJson($this->api('/leads'), ['first_name' => 'X', 'phone' => '1', 'source' => 'WEB'])->assertStatus(403);
        }

        $seller = $this->login('acme', 'vera@acme.com');
        $this->withToken($seller)->getJson($this->api("/companies/{$company}"))->assertOk();
        $this->withToken($seller)->postJson($this->api('/leads'), ['first_name' => 'X', 'phone' => '1', 'source' => 'WEB'])
            ->assertCreated()->assertJsonPath('data.owner.id', fn (string $id) => $id !== '');

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->api('/companies'))->assertStatus(401);
    }

    public function test_crm_data_is_isolated_between_organizations(): void
    {
        $company = $this->company(['tax_id' => '20100123456']);
        $opportunity = $this->opportunity($company);
        $lead = $this->lead();

        $this->provisionTenant('globex', 'admin@globex.com');
        $globex = $this->login('globex', 'admin@globex.com');
        $url = fn (string $path) => $this->tenantUrl('globex', "/crm{$path}");

        $this->withToken($globex)->getJson($url('/companies'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($globex)->getJson($url("/companies/{$company}"))->assertStatus(404);
        $this->withToken($globex)->getJson($url("/opportunities/{$opportunity}"))->assertStatus(404);
        $this->withToken($globex)->postJson($url("/leads/{$lead}/discard"), ['reason' => 'Ajeno'])->assertStatus(404);
        $this->withToken($globex)->postJson($url('/opportunities'), ['company_id' => $company, 'title' => 'X'])->assertStatus(422);

        // El mismo RUC puede existir en otra organización
        $this->withToken($globex)->postJson($url('/companies'), ['name' => 'Minera Andes', 'tax_id' => '20100123456'])->assertCreated();
    }
}
