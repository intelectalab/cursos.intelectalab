<?php

namespace Tests\Feature\Sales;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Sales\Domain\Models\ExchangeRate;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RF-CUR-001: el tipo de cambio de la organización es un dato propio, con su
 * fuente y su historial; se escribe a mano o se toma solo de SUNAT.
 */
class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-12 15:00:00');
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path = ''): string
    {
        return $this->tenantUrl('acme', '/settings/exchange-rate'.$path);
    }

    /**
     * Lo que responde el servicio que republica el tipo de cambio de SUNAT.
     * Un stub nuevo no reemplaza al anterior de la misma dirección: se parte de cero.
     */
    private function sunat(?float $sell, string $date = '2026-10-12'): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(['api.apis.net.pe/*' => $sell === null
            ? Http::response(['message' => 'Servicio no disponible'], 503)
            : Http::response(['origen' => 'SUNAT', 'compra' => $sell - 0.007, 'venta' => $sell, 'moneda' => 'USD', 'fecha' => $date])]);
    }

    public function test_the_rate_is_typed_by_hand_and_every_value_is_kept(): void
    {
        $admin = fn () => $this->withToken($this->admin);

        $admin()->getJson($this->api())->assertOk()
            ->assertJsonPath('data.mode', 'MANUAL')
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('meta.sources.0.code', 'SUNAT');

        $admin()->postJson($this->api('/manual'), ['rate' => 0])->assertStatus(422);
        $admin()->postJson($this->api('/manual'), ['rate' => 3.75])->assertCreated()
            ->assertJsonPath('data.current.rate', 3.75)
            ->assertJsonPath('data.current.source', 'MANUAL')
            ->assertJsonPath('data.current.effective_date', '2026-10-12')
            ->assertJsonPath('data.stale', false);

        // Escribir el mismo valor no agrega un registro; uno distinto sí
        $admin()->postJson($this->api('/manual'), ['rate' => 3.75])->assertCreated()->assertJsonCount(1, 'data.history');
        $this->travelTo('2026-10-13 15:00:00');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($this->admin)->postJson($this->api('/manual'), ['rate' => 3.8])->assertCreated()
            ->assertJsonPath('data.current.rate', 3.8)
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.1.rate', 3.75);

        // Pasados unos días sin tocarlo, se avisa que está desactualizado
        $this->travelTo('2026-10-20 15:00:00');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($this->admin)->getJson($this->api())->assertJsonPath('data.stale', true);

        // En manual no hay nada que traer de la fuente
        $this->withToken($this->admin)->postJson($this->api('/refresh'))->assertStatus(422);

        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => $this->assertSame(2, AuditEvent::query()->where('action', 'exchange_rates.recorded')->count()));
    }

    public function test_the_rate_follows_sunat_every_day_with_an_optional_surcharge(): void
    {
        $admin = fn () => $this->withToken($this->admin);

        // Al pasar a automático se trae el valor del día
        $this->sunat(3.457);
        $admin()->putJson($this->api(), ['mode' => 'AUTO', 'margin_percent' => 0])->assertOk()
            ->assertJsonPath('data.mode', 'AUTO')
            ->assertJsonPath('data.current.rate', 3.457)
            ->assertJsonPath('data.current.source', 'SUNAT')
            ->assertJsonPath('data.current.created_by', 'Ada Admin');

        // Con recargo se cobran más dólares por el mismo precio: 3.457 / 1.02
        $admin()->putJson($this->api(), ['mode' => 'AUTO', 'margin_percent' => 25])->assertStatus(422);
        $admin()->putJson($this->api(), ['mode' => 'AUTO', 'margin_percent' => 2])->assertOk()
            ->assertJsonPath('data.margin_percent', 2)
            ->assertJsonPath('data.current.rate', 3.3892)
            ->assertJsonPath('data.current.official_rate', 3.457)
            ->assertJsonPath('data.current.margin_percent', 2);

        // En automático no se escribe a mano
        $admin()->postJson($this->api('/manual'), ['rate' => 3.9])->assertStatus(422);

        // La tarea diaria registra el valor nuevo; repetirla el mismo día no duplica
        $this->sunat(3.47, '2026-10-13');
        $this->artisan('exchange-rates:sync')->assertSuccessful();
        $this->artisan('exchange-rates:sync')->assertSuccessful();

        $this->withToken($this->admin)->getJson($this->api())
            ->assertJsonPath('data.current.rate', 3.402)
            ->assertJsonPath('data.current.effective_date', '2026-10-13')
            ->assertJsonPath('data.current.created_by', null)
            ->assertJsonCount(3, 'data.history');

        // Si la fuente falla se conserva el último valor y se dice por qué no cambió
        $this->sunat(null);
        $this->artisan('exchange-rates:sync')->assertSuccessful();
        $this->withToken($this->admin)->postJson($this->api('/refresh'))->assertStatus(502)->assertJsonPath('code', 'GATEWAY_ERROR');
        $this->withToken($this->admin)->getJson($this->api())->assertJsonPath('data.current.rate', 3.402)->assertJsonCount(3, 'data.history');

        // De vuelta en manual, la tarea diaria ya no la toca
        $this->withToken($this->admin)->putJson($this->api(), ['mode' => 'MANUAL'])->assertOk()->assertJsonPath('data.mode', 'MANUAL');
        $this->sunat(3.6, '2026-10-14');
        $this->artisan('exchange-rates:sync')->assertSuccessful();
        Http::assertNothingSent();
        $this->withToken($this->admin)->postJson($this->api('/manual'), ['rate' => 3.9])->assertCreated()->assertJsonPath('data.current.source', 'MANUAL');
    }

    public function test_only_who_manages_the_settings_changes_the_rate_and_each_organization_has_its_own(): void
    {
        $this->withToken($this->admin)->postJson($this->api('/manual'), ['rate' => 3.75])->assertCreated();

        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $student = $this->login('acme', 'ana@acme.com');
        $this->withToken($student)->getJson($this->api())->assertForbidden();
        $this->withToken($student)->postJson($this->api('/manual'), ['rate' => 1])->assertForbidden();
        $this->withToken($student)->putJson($this->api(), ['mode' => 'AUTO'])->assertForbidden();

        $this->provisionTenant('beta', 'admin@beta.com');
        $this->withToken($this->login('beta', 'admin@beta.com'))->getJson($this->tenantUrl('beta', '/settings/exchange-rate'))
            ->assertOk()->assertJsonPath('data.current', null);

        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => $this->assertSame(1, ExchangeRate::query()->count()));
    }
}
