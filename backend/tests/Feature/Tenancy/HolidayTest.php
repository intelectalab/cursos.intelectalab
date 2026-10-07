<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S12: feriados de la organización y su efecto en los plazos en días hábiles.
 */
class HolidayTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM = [
        'type' => 'RECLAMO',
        'first_name' => 'Rosa', 'last_name' => 'Vargas',
        'doc_type' => 'DNI', 'doc_number' => '45678912',
        'address' => 'Av. Arequipa 123, Lima', 'email' => 'rosa@correo.pe', 'phone' => '999 888 777',
        'good_type' => 'SERVICE', 'good_description' => 'Curso Power BI desde cero',
        'detail' => 'Pagué el curso hace una semana y todavía no tengo acceso.',
        'request' => 'Que me activen el curso o me devuelvan el dinero.',
        'accepted' => true,
    ];

    public function test_holidays_are_added_listed_by_year_and_removed_with_audit(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/settings/holidays');

        $id = $this->withToken($token)->postJson($url, ['date' => '2026-11-27', 'name' => 'Aniversario de la academia'])
            ->assertCreated()
            ->assertJsonPath('meta.year', 2026)
            ->assertJsonPath('data.0.date', '2026-11-27')
            ->json('data.0.id');

        $this->withToken($token)->postJson($url, ['date' => '2026-11-27', 'name' => 'Repetido'])
            ->assertStatus(422)->assertJsonStructure(['details' => ['date']]);
        $this->withToken($token)->postJson($url, ['date' => '27/11/2026', 'name' => 'Formato'])
            ->assertStatus(422)->assertJsonStructure(['details' => ['date']]);

        $this->withToken($token)->getJson($url.'?year=2027')->assertOk()->assertJsonCount(0, 'data');

        $this->withToken($token)->deleteJson("{$url}/{$id}")->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(
            ['holidays.created', 'holidays.deleted'],
            AuditEvent::query()->where('action', 'like', 'holidays.%')->orderBy('created_at')->orderBy('action')->pluck('action')->all()
        );
    }

    public function test_national_holidays_of_a_year_are_loaded_once_including_easter(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/settings/holidays/load-national');

        $response = $this->withToken($token)->postJson($url, ['year' => 2026])
            ->assertOk()
            ->assertJsonPath('meta.added', 16)
            ->assertJsonCount(16, 'data');

        // Semana Santa de 2026: Pascua cae el 5 de abril
        $dates = array_column($response->json('data'), 'name', 'date');
        $this->assertSame('Jueves Santo', $dates['2026-04-02']);
        $this->assertSame('Viernes Santo', $dates['2026-04-03']);
        $this->assertSame('Combate de Angamos', $dates['2026-10-08']);

        // Repetir la carga no duplica
        $this->withToken($token)->postJson($url, ['year' => 2026])
            ->assertOk()
            ->assertJsonPath('meta.added', 0)
            ->assertJsonCount(16, 'data');
    }

    public function test_a_holiday_inside_the_term_pushes_the_claim_deadline_one_business_day(): void
    {
        // Lunes 5 de octubre de 2026: 15 días hábiles después es el lunes 26
        $this->travelTo('2026-10-05 10:00:00');
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');

        // Jueves 8, dentro del plazo; sábado 10, que ya no era hábil
        $this->withToken($token)->postJson($this->tenantUrl('acme', '/settings/holidays'), ['date' => '2026-10-08', 'name' => 'Combate de Angamos'])->assertCreated();
        $this->withToken($token)->postJson($this->tenantUrl('acme', '/settings/holidays'), ['date' => '2026-10-10', 'name' => 'Feriado en sábado'])->assertCreated();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->postJson($this->tenantUrl('acme', '/claims'), self::CLAIM)
            ->assertCreated()
            ->assertJsonPath('data.due_at', '2026-10-27');
    }

    public function test_holidays_belong_to_their_organization_and_require_permission(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $alfaToken = $this->login('alfa', 'admin@alfa.com');
        $betaToken = $this->login('beta', 'admin@beta.com');

        $id = $this->withToken($alfaToken)->postJson($this->tenantUrl('alfa', '/settings/holidays'), ['date' => '2026-11-27', 'name' => 'Solo de alfa'])
            ->assertCreated()->json('data.0.id');

        $this->withToken($betaToken)->getJson($this->tenantUrl('beta', '/settings/holidays?year=2026'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($betaToken)->deleteJson($this->tenantUrl('beta', "/settings/holidays/{$id}"))->assertNotFound();

        $student = $this->login('alfa', $this->createUser($alfa, RoleCatalog::STUDENT)->email);
        $this->withToken($student)->getJson($this->tenantUrl('alfa', '/settings/holidays?year=2026'))->assertForbidden();
        $this->withToken($student)->postJson($this->tenantUrl('alfa', '/settings/holidays'), ['date' => '2026-12-24', 'name' => 'No autorizado'])->assertForbidden();
    }
}
