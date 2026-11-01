<?php

namespace Tests\Feature\Security;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Platform\Http\Middleware\EnsurePlatformAdmin;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RNF-SEC-001: controles que deben cumplirse en toda la API, no módulo por módulo.
 * Una ruta o una tabla nueva que se salte la regla hace fallar estas pruebas.
 */
class SecurityBaselineTest extends TestCase
{
    use RefreshDatabase;

    /** Rutas que se usan sin sesión. Agregar una aquí es una decisión, no un descuido. */
    private const PUBLIC_ROUTES = [
        'POST api/v1/auth/login',
        // Segundo paso del inicio de sesión: lo protege el paso intermedio cifrado, no una sesión
        'POST api/v1/auth/two-factor',
        'POST api/v1/auth/register',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/reset-password',
        'POST api/v1/platform/auth/login',
        'POST api/v1/tenants',
        'GET api/v1/tenant',
        'GET api/v1/public/plans',
        'GET api/v1/public-files/{file}',
        'GET api/v1/catalog/courses',
        'GET api/v1/catalog/courses/{course}',
        'GET api/v1/catalog/courses/{course}/companions',
        'GET api/v1/catalog/packages',
        'GET api/v1/catalog/packages/{package}',
        'GET api/v1/refund-policy',
        // Archivos SCORM del marco del aula: el permiso va en el enlace firmado
        'GET api/v1/scorm-content/{token}/{path}',
        'GET api/v1/certificates/verify/{code}',
        'GET api/v1/claims/form',
        'POST api/v1/claims',
        'POST api/v1/claims/lookup',
        'POST api/v1/payments/webhooks/{gateway}',
        'POST api/v1/platform/payments/webhooks/{gateway}',
        'GET api/v1/site/pages',
        'GET api/v1/site/pages/{slug}',
        // Aplicación instalable: el navegador pide manifiesto e ícono sin sesión
        'GET api/v1/app/manifest',
        'GET api/v1/app/icon/{size}/{maskable?}',
        // Darse de baja de correos: el permiso va en la firma del enlace
        'GET api/v1/email-preferences/{token}',
        'PUT api/v1/email-preferences/{token}',
        'POST api/v1/email-preferences/{token}/unsubscribe',
    ];

    /** Rutas con sesión que no piden permiso: la cuenta de la propia persona. */
    private const SELF_SERVICE_ROUTES = [
        'GET api/v1/auth/me',
        'POST api/v1/auth/logout',
        'PUT api/v1/auth/password',
        'GET api/v1/auth/sessions',
        'DELETE api/v1/auth/sessions',
        'DELETE api/v1/auth/sessions/{session}',
        // Verificación en dos pasos de la cuenta propia
        'GET api/v1/auth/two-factor',
        'POST api/v1/auth/two-factor/start',
        'POST api/v1/auth/two-factor/confirm',
        'DELETE api/v1/auth/two-factor',
        'POST api/v1/auth/two-factor/recovery-codes',
        // Correos que recibe la propia persona
        'GET api/v1/auth/email-preferences',
        'PUT api/v1/auth/email-preferences',
        // Avisos al teléfono de los dispositivos propios
        'GET api/v1/auth/push',
        'POST api/v1/auth/push/subscriptions',
        'DELETE api/v1/auth/push/subscriptions',
        'POST api/v1/auth/push/test',
        // Si la academia emite comprobantes (para preguntar boleta o factura al comprar)
        'GET api/v1/invoicing/status',
        // Manual de uso en PDF del Centro de ayuda
        'GET api/v1/help/manual',
    ];

    public function test_every_route_requires_a_session_unless_it_is_declared_public(): void
    {
        $open = $this->apiRoutes()
            ->reject(fn (RoutingRoute $route) => $this->has($route, Authenticate::class))
            ->map(fn (RoutingRoute $route) => $this->signature($route))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(self::PUBLIC_ROUTES, $open);
    }

    public function test_every_route_with_session_requires_a_permission_unless_it_is_self_service(): void
    {
        $unguarded = $this->apiRoutes()
            ->filter(fn (RoutingRoute $route) => $this->has($route, Authenticate::class))
            ->reject(fn (RoutingRoute $route) => $this->has($route, RequirePermission::class) || $this->has($route, EnsurePlatformAdmin::class))
            ->map(fn (RoutingRoute $route) => $this->signature($route))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(self::SELF_SERVICE_ROUTES, $unguarded);
    }

    public function test_every_table_with_tenant_id_enforces_row_level_security(): void
    {
        $unprotected = DB::select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = current_schema()
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            WHERE c.relkind = 'r'
              AND (
                  NOT c.relrowsecurity
                  OR NOT c.relforcerowsecurity
                  OR NOT EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid)
              )
        SQL);

        $this->assertSame([], array_column($unprotected, 'name'));
    }

    public function test_api_responses_carry_security_headers_and_a_request_id(): void
    {
        $this->provisionTenant('acme');

        $response = $this->getJson($this->tenantUrl('acme', '/tenant'))->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->assertHeader('X-Request-ID');
        $response->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://acme.localhost/api/v1/tenant')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_without_a_session_the_api_answers_401_even_if_the_client_does_not_ask_for_json(): void
    {
        $this->provisionTenant('acme');

        // Sin «Accept: application/json» Laravel intentaba redirigir a una pantalla de acceso que no existe (500)
        $this->get($this->tenantUrl('acme', '/users'))->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->get($this->tenantUrl('acme', '/dashboard/sales?from=2026-01-01'))->assertUnauthorized();
    }

    public function test_forwarded_https_is_believed_only_from_a_trusted_proxy(): void
    {
        $this->provisionTenant('acme');
        $forwarded = ['X-Forwarded-Proto' => 'https'];

        // Sin proxy declarado, cualquiera podría fingir la cabecera: se ignora
        $this->getJson($this->tenantUrl('acme', '/tenant'), $forwarded)
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        config(['tenancy.trusted_proxies' => '*']);
        $this->app->getProvider(AppServiceProvider::class)->configureTrustedProxies();

        try {
            $this->getJson($this->tenantUrl('acme', '/tenant'), $forwarded)
                ->assertOk()
                ->assertHeader('Strict-Transport-Security');
        } finally {
            TrustProxies::flushState();
        }
    }

    public function test_only_the_platform_own_origins_may_call_the_api_from_a_browser(): void
    {
        $this->provisionTenant('acme');
        $url = $this->tenantUrl('acme', '/tenant');

        $this->getJson($url, ['Origin' => 'http://acme.localhost:5173'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://acme.localhost:5173');

        $this->getJson($url, ['Origin' => 'https://sitio-ajeno.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        // Un dominio que solo termina igual no es un subdominio de la plataforma
        $this->getJson($url, ['Origin' => 'http://acme.localhost.sitio-ajeno.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_general_rate_limit_counts_each_signed_in_user_separately(): void
    {
        config(['identity.api_rate_limit' => 3]);
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $student = $this->createUser($tenant, RoleCatalog::STUDENT);
        $adminToken = $this->login('acme', 'admin@acme.com');
        $studentToken = $this->login('acme', $student->email);
        $url = $this->tenantUrl('acme', '/auth/me');

        for ($i = 0; $i < 3; $i++) {
            $this->withToken($adminToken)->getJson($url)->assertOk();
        }

        $this->withToken($adminToken)->getJson($url)
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        // Misma dirección, otra persona: su cuenta de peticiones es aparte
        $this->withToken($studentToken)->getJson($url)->assertOk();
    }

    /**
     * @return Collection<int, RoutingRoute>
     */
    private function apiRoutes(): Collection
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1/'));
    }

    private function has(RoutingRoute $route, string $middleware): bool
    {
        return collect(Route::gatherRouteMiddleware($route))
            ->contains(fn (string $name) => $name === $middleware || str_starts_with($name, $middleware.':'));
    }

    private function signature(RoutingRoute $route): string
    {
        return collect($route->methods())->reject(fn (string $method) => $method === 'HEAD')->implode('|').' '.$route->uri();
    }
}
