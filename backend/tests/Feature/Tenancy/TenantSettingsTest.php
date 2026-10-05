<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Academia Acme',
            'legal_name' => 'Acme Educación S.A.C.',
            'tax_id' => '20600000001',
            'primary_currency' => 'PEN',
            'secondary_currency' => 'USD',
            'timezone' => 'America/Lima',
            'contact_email' => 'contacto@acme.com',
            'contact_phone' => '01 555 0100',
            'contact_address' => 'Av. Principal 123, Lima',
            'terms' => 'Términos de la academia.',
            'privacy_policy' => 'Política de privacidad.',
            'session_minutes' => 30,
        ];
    }

    public function test_settings_have_defaults_and_are_updated_with_audit(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $url = $this->tenantUrl('acme', '/settings');

        $this->withToken($token)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.name', 'Organización acme')
            ->assertJsonPath('data.primary_currency', 'PEN')
            ->assertJsonPath('data.timezone', 'America/Lima')
            ->assertJsonPath('data.session_minutes', 30)
            ->assertJsonPath('meta.currencies', ['PEN', 'USD']);

        $this->withToken($token)->putJson($url, $this->payload())
            ->assertOk()
            ->assertJsonPath('data.name', 'Academia Acme')
            ->assertJsonPath('data.secondary_currency', 'USD');

        $this->withToken($token)->getJson($url)->assertJsonPath('data.contact_email', 'contacto@acme.com');
        $this->getJson($this->tenantUrl('acme', '/tenant'))->assertJsonPath('data.name', 'Academia Acme');

        $event = AuditEvent::query()->where('action', 'tenant_settings.updated')->firstOrFail();
        $this->assertSame('Organización acme', $event->before['name']);
        $this->assertSame('Academia Acme', $event->after['name']);
        $this->assertArrayNotHasKey('primary_currency', $event->after);
    }

    public function test_settings_are_validated(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');

        $this->withToken($token)->putJson($this->tenantUrl('acme', '/settings'), $this->payload([
            'primary_currency' => 'EUR',
            'secondary_currency' => 'EUR',
            'timezone' => 'Marte/Olimpo',
            'session_minutes' => 2,
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['primary_currency', 'secondary_currency', 'timezone', 'session_minutes']]);

        $this->withToken($token)->putJson($this->tenantUrl('acme', '/settings'), $this->payload(['secondary_currency' => 'PEN']))
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['secondary_currency']]);
    }

    public function test_settings_are_isolated_and_require_permission(): void
    {
        $alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $student = $this->createUser($alfa, RoleCatalog::STUDENT);

        $this->withToken($this->login('alfa', 'admin@alfa.com'))
            ->putJson($this->tenantUrl('alfa', '/settings'), $this->payload(['name' => 'Solo Alfa']))
            ->assertOk();

        $this->withToken($this->login('beta', 'admin@beta.com'))->getJson($this->tenantUrl('beta', '/settings'))
            ->assertJsonPath('data.name', 'Organización beta')
            ->assertJsonPath('data.contact_email', null);

        $studentToken = $this->login('alfa', $student->email);
        $this->withToken($studentToken)->getJson($this->tenantUrl('alfa', '/settings'))->assertForbidden();
        $this->withToken($studentToken)->putJson($this->tenantUrl('alfa', '/settings'), $this->payload())->assertForbidden();
    }
}
