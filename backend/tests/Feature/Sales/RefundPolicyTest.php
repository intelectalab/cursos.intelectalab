<?php

namespace Tests\Feature\Sales;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Política de reembolsos: la academia parte de la plantilla de IntelectaLab,
 * la adapta y la publica para sus compradores.
 */
class RefundPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    public function test_the_academy_adapts_the_template_and_publishes_it(): void
    {
        $admin = $this->login('acme', 'admin@acme.com');

        // Sin política, no hay nada publicado; la plantilla ya trae el nombre de la academia
        $this->getJson($this->api('/refund-policy'))->assertOk()->assertJsonPath('data', null);
        $template = $this->withToken($admin)->getJson($this->api('/settings/refund-policy'))
            ->assertOk()
            ->assertJsonPath('data.text', null)
            ->assertJsonPath('data.default_days', 7)
            ->json('data.template');
        $this->assertStringContainsString($this->tenant->name, $template);
        $this->assertStringContainsString('7 días', $template);

        $this->withToken($admin)->putJson($this->api('/settings/refund-policy'), ['text' => str_replace('7 días', '10 días', $template), 'days' => 10])
            ->assertOk()
            ->assertJsonPath('data.days', 10);

        $this->flushHeaders()->getJson($this->api('/refund-policy'))
            ->assertOk()
            ->assertJsonPath('data.days', 10)
            ->assertJson(fn ($json) => $json->where('data.text', fn ($text) => str_contains($text, '10 días'))->etc());

        // Vaciarla la despublica
        $this->withToken($admin)->putJson($this->api('/settings/refund-policy'), ['text' => '', 'days' => null])->assertOk();
        $this->flushHeaders()->getJson($this->api('/refund-policy'))->assertJsonPath('data', null);
    }

    public function test_only_who_edits_the_settings_changes_the_policy(): void
    {
        $this->createUser($this->tenant, RoleCatalog::SUPPORT, ['email' => 'soporte@acme.com']);
        $token = $this->login('acme', 'soporte@acme.com');

        $this->withToken($token)->putJson($this->api('/settings/refund-policy'), ['text' => 'Sin reembolsos.', 'days' => 1])->assertForbidden();
        $this->withToken($this->login('acme', 'admin@acme.com'))->putJson($this->api('/settings/refund-policy'), ['text' => 'x', 'days' => 400])
            ->assertStatus(422)->assertJsonValidationErrors(['days'], 'details');
    }
}
