<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El manual en PDF del Centro de ayuda: lo baja cualquiera con sesión en la academia.
 */
class HelpManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_user_of_the_academy_downloads_the_manual_and_a_visitor_does_not(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->createUser($tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);

        $response = $this->withToken($this->login('acme', 'ana@acme.com'))->get($this->tenantUrl('acme', '/help/manual'))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', file_get_contents($response->baseResponse->getFile()->getPathname()));

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->tenantUrl('acme', '/help/manual'))->assertUnauthorized();
    }
}
