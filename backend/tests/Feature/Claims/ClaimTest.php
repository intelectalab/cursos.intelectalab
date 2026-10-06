<?php

namespace Tests\Feature\Claims;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * S10 entrega 2: Libro de Reclamaciones. Formulario → código → registro →
 * seguimiento → respuesta (FP-20, RN-018).
 */
class ClaimTest extends TestCase
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
     * El consumidor llena la hoja sin sesión.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submit(array $attributes = [], string $slug = 'acme'): TestResponse
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->postJson($this->tenantUrl($slug, '/claims'), $attributes + [
            'type' => 'RECLAMO',
            'first_name' => 'Rosa', 'last_name' => 'Vargas',
            'doc_type' => 'DNI', 'doc_number' => '45678912',
            'address' => 'Av. Arequipa 123, Lima', 'email' => 'Rosa@Correo.pe', 'phone' => '999 888 777',
            'good_type' => 'SERVICE', 'good_description' => 'Curso Power BI desde cero', 'amount' => 150, 'order_reference' => 'N.º 00012',
            'detail' => 'Pagué el curso hace una semana y todavía no tengo acceso.',
            'request' => 'Que me activen el curso o me devuelvan el dinero.',
            'accepted' => true,
        ]);
    }

    private function lookup(string $code, string $doc, string $slug = 'acme'): TestResponse
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->postJson($this->tenantUrl($slug, '/claims/lookup'), ['code' => $code, 'doc_number' => $doc]);
    }

    public function test_a_consumer_files_a_claim_gets_a_code_and_follows_it_until_the_answer(): void
    {
        // Lunes 5 de octubre: 15 días hábiles después es el lunes 26
        $this->travelTo('2026-10-05 10:00:00');
        $this->withToken($this->login('acme', 'admin@acme.com'))->putJson($this->api('/settings'), [
            'name' => 'Academia Acme', 'legal_name' => 'Acme Educación S.A.C.', 'tax_id' => '20123456789',
            'primary_currency' => 'PEN', 'timezone' => 'America/Lima', 'contact_address' => 'Jr. Unión 500, Lima',
            'grade_scale' => 20, 'session_minutes' => 30,
        ])->assertOk();
        $admin = $this->login('acme', 'admin@acme.com');

        $this->flushHeaders();
        $this->getJson($this->api('/claims/form'))
            ->assertOk()
            ->assertJsonPath('data.provider.legal_name', 'Acme Educación S.A.C.')
            ->assertJsonPath('data.provider.tax_id', '20123456789')
            ->assertJsonPath('data.response_days', 15);

        $claim = $this->submit()
            ->assertCreated()
            ->assertJsonPath('data.code', 'LR-2026-000001')
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.due_at', '2026-10-26')
            ->assertJsonPath('data.consumer.email', 'rosa@correo.pe')
            ->assertJsonPath('data.good.currency', 'PEN')
            ->assertJsonPath('data.provider.address', 'Jr. Unión 500, Lima')
            ->assertJsonPath('data.response', null)
            ->assertJsonMissingPath('data.notes')
            ->json('data');

        // Seguimiento con código y documento; con otro documento no existe
        $this->lookup('lr-2026-000001', '45678912')->assertOk()->assertJsonPath('data.status', 'RECEIVED');
        $this->lookup('LR-2026-000001', '11111111')->assertStatus(404);

        // El personal la revisa, deja una nota y responde
        $this->withToken($admin)->getJson($this->api('/claims?status=PENDING'))
            ->assertOk()->assertJsonPath('data.0.code', 'LR-2026-000001')->assertJsonPath('meta.counts.pending', 1);
        $this->withToken($admin)->postJson($this->api("/claims/{$claim['id']}/review"))->assertOk()->assertJsonPath('data.status', 'IN_REVIEW');
        $this->withToken($admin)->postJson($this->api("/claims/{$claim['id']}/notes"), ['body' => 'El pago figura sin confirmar.'])
            ->assertCreated()->assertJsonCount(1, 'data.notes');
        $this->withToken($admin)->postJson($this->api("/claims/{$claim['id']}/respond"), ['body' => 'Confirmamos su pago y activamos el curso. Disculpe la demora.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ANSWERED')
            ->assertJsonPath('data.response.by', 'Ada Admin')
            ->assertJsonPath('data.history.2.by', 'Ada Admin');

        // La respuesta es una sola
        $this->withToken($admin)->postJson($this->api("/claims/{$claim['id']}/respond"), ['body' => 'Otra respuesta distinta.'])
            ->assertStatus(409)->assertJsonPath('code', 'CLAIM_ALREADY_ANSWERED');

        // El consumidor ve el estado y la respuesta, pero no la nota interna ni quién atendió
        $seen = $this->lookup('LR-2026-000001', '45678912')->assertOk()->json('data');
        $this->assertSame('ANSWERED', $seen['status']);
        $this->assertSame('Confirmamos su pago y activamos el curso. Disculpe la demora.', $seen['response']['body']);
        $this->assertSame(['RECEIVED', 'IN_REVIEW', 'ANSWERED'], array_column($seen['history'], 'status'));
        $this->assertArrayNotHasKey('notes', $seen);
        $this->assertArrayNotHasKey('by', $seen['response']);
        $this->assertArrayNotHasKey('by', $seen['history'][2]);

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(1, AuditEvent::query()->where('action', 'claims.registered')->count());
            $this->assertSame(2, AuditEvent::query()->where('action', 'claims.status_changed')->count());
        });
    }

    public function test_the_form_is_validated(): void
    {
        $this->submit(['accepted' => false])->assertStatus(422);
        $this->submit(['doc_number' => '123'])->assertStatus(422);
        $this->submit(['doc_type' => 'RUC', 'doc_number' => '45678912'])->assertStatus(422);
        $this->submit(['detail' => 'Corto'])->assertStatus(422);

        // Un menor necesita los datos de su apoderado
        $this->submit(['is_minor' => true])->assertStatus(422)->assertJsonStructure(['details' => ['guardian_name', 'guardian_doc_number']]);
    }

    public function test_minors_keep_their_guardian_and_codes_are_sequential_per_year(): void
    {
        $this->travelTo('2026-12-30 10:00:00');

        $this->submit(['is_minor' => true, 'guardian_name' => 'Elena Vargas', 'guardian_doc_number' => '40111222', 'type' => 'QUEJA'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'LR-2026-000001')
            ->assertJsonPath('data.type', 'QUEJA')
            ->assertJsonPath('data.consumer.guardian_name', 'Elena Vargas');
        // Sin monto no se guarda moneda; los datos de apoderado de un adulto se descartan
        $this->submit(['amount' => null, 'guardian_name' => 'No aplica'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'LR-2026-000002')
            ->assertJsonPath('data.good.currency', null)
            ->assertJsonPath('data.consumer.guardian_name', null);

        // El correlativo vuelve a empezar con el año
        $this->travelTo('2027-01-04 10:00:00');
        $this->submit()->assertCreated()->assertJsonPath('data.code', 'LR-2027-000001');
    }

    public function test_the_response_deadline_follows_the_setting_and_overdue_claims_are_flagged(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($admin)->putJson($this->api('/settings'), [
            'name' => 'Academia Acme', 'primary_currency' => 'PEN', 'timezone' => 'America/Lima',
            'grade_scale' => 20, 'session_minutes' => 30, 'claim_response_days' => 5,
        ])->assertOk()->assertJsonPath('data.claim_response_days', 5);
        $this->withToken($admin)->putJson($this->api('/settings'), [
            'name' => 'Academia Acme', 'primary_currency' => 'PEN', 'timezone' => 'America/Lima',
            'grade_scale' => 20, 'session_minutes' => 30, 'claim_response_days' => 90,
        ])->assertStatus(422);

        // 5 días hábiles desde el lunes 5: lunes 12
        $this->submit()->assertCreated()->assertJsonPath('data.due_at', '2026-10-12');

        $this->travelTo('2026-10-12 18:00:00');
        $admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($admin)->getJson($this->api('/claims'))->assertJsonPath('data.0.overdue', false)->assertJsonPath('meta.counts.overdue', 0);

        $this->travelTo('2026-10-13 09:00:00');
        $admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($admin)->getJson($this->api('/claims?status=OVERDUE'))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.overdue', true)->assertJsonPath('meta.counts.overdue', 1);
        $this->withToken($admin)->getJson($this->api('/claims?search=45678912'))->assertJsonCount(1, 'data');
        $this->withToken($admin)->getJson($this->api('/claims?type=QUEJA'))->assertJsonCount(0, 'data');
    }

    public function test_the_registration_day_is_the_organizations_local_day(): void
    {
        // En Lima todavía es jueves 31 de diciembre por la noche; en el servidor (UTC) ya es viernes 1 de enero
        $this->travelTo('2027-01-01 02:00:00');

        $this->submit()->assertCreated()
            ->assertJsonPath('data.code', 'LR-2026-000001')
            // 15 días hábiles desde el jueves 31
            ->assertJsonPath('data.due_at', '2027-01-21');
    }

    public function test_claims_respect_roles_and_organizations(): void
    {
        $claim = $this->submit()->assertCreated()->json('data');

        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::SUPPORT, ['email' => 'sol@acme.com']);

        $this->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api('/claims'))->assertStatus(403);

        // Soporte las consulta, pero no las responde
        $support = $this->login('acme', 'sol@acme.com');
        $this->withToken($support)->getJson($this->api("/claims/{$claim['id']}"))->assertOk()->assertJsonPath('data.consumer.email', 'rosa@correo.pe');
        $this->withToken($support)->postJson($this->api("/claims/{$claim['id']}/respond"), ['body' => 'Respuesta de soporte.'])->assertStatus(403);

        // Otra organización no la ve, ni por la lista ni por el seguimiento público
        $this->provisionTenant('globex', 'admin@globex.com');
        $globex = $this->login('globex', 'admin@globex.com');
        $this->withToken($globex)->getJson($this->tenantUrl('globex', '/claims'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($globex)->getJson($this->tenantUrl('globex', "/claims/{$claim['id']}"))->assertStatus(404);
        $this->lookup($claim['code'], '45678912', 'globex')->assertStatus(404);

        // Cada organización lleva su propio correlativo
        $this->submit([], 'globex')->assertCreated()->assertJsonPath('data.code', $claim['code']);
    }
}
