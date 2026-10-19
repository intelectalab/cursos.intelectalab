<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Modo por ruta: todas las organizaciones comparten el dominio central y
 * cada una vive en dominio/a/{slug}. La aplicación web indica la
 * organización con el encabezado X-Tenant; los avisos de las pasarelas, con
 * ?tenant=. Los enlaces que arma el sistema llevan la ruta.
 */
class PathTenancyTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL = 'https://cursos.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.mode' => 'path', 'tenancy.central_domain' => 'cursos.test', 'tenancy.public_url' => null, 'app.url' => self::CENTRAL]);
    }

    private function api(string $path): string
    {
        return self::CENTRAL.'/api/v1'.$path;
    }

    /**
     * @return array<string, string>
     */
    private function academy(string $slug): array
    {
        return ['X-Tenant' => $slug, 'Origin' => self::CENTRAL];
    }

    private function loginAt(string $slug, string $email): string
    {
        return $this->postJson($this->api('/auth/login'), ['email' => $email, 'password' => self::PASSWORD], $this->academy($slug))
            ->assertOk()->json('data.token');
    }

    public function test_the_academy_comes_from_the_header_and_without_it_the_central_domain_answers(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');

        $this->getJson($this->api('/tenant'), $this->academy('acme'))->assertOk()
            ->assertJsonPath('data.slug', 'acme')
            ->assertJsonPath('meta.tenancy_mode', 'path');

        $this->getJson($this->api('/tenant'))->assertOk()->assertJsonPath('data', null)->assertJsonPath('meta.tenancy_mode', 'path');
        $this->getJson($this->api('/tenant'), ['X-Tenant' => 'no-existe'])->assertNotFound()->assertJsonPath('code', 'TENANT_NOT_FOUND');
        // Algo que no puede ser una organización se trata como el dominio central
        $this->getJson($this->api('/tenant'), ['X-Tenant' => '../acme'])->assertOk()->assertJsonPath('data', null);

        // Con subdominios, el encabezado no cuenta
        config(['tenancy.mode' => 'subdomain']);
        $this->getJson($this->api('/tenant'), $this->academy('acme'))->assertOk()->assertJsonPath('data', null);
    }

    public function test_a_session_only_works_in_its_own_academy(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $this->provisionTenant('otra', 'admin@otra.com');

        $token = $this->loginAt('acme', 'admin@acme.com');

        $this->withToken($token)->getJson($this->api('/auth/me'), $this->academy('acme'))->assertOk()->assertJsonPath('data.user.email', 'admin@acme.com');

        // Cambiar el encabezado no lleva a otra academia, ni la clave sirve allá
        $this->withToken($token)->getJson($this->api('/users'), $this->academy('otra'))->assertUnauthorized();
        $this->flushHeaders();
        $this->postJson($this->api('/auth/login'), ['email' => 'admin@acme.com', 'password' => self::PASSWORD], $this->academy('otra'))->assertUnauthorized();
    }

    public function test_the_links_the_system_builds_carry_the_academy_path(): void
    {
        Mail::fake();
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->createUser($tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);

        $this->postJson($this->api('/auth/forgot-password'), ['email' => 'ana@acme.com'], $this->academy('acme'))->assertOk();

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => str_starts_with($mail->url, self::CENTRAL.'/a/acme/reset-password?'));
    }

    public function test_public_images_load_without_saying_which_academy_they_belong_to(): void
    {
        Storage::fake(config('files.disk'));
        $this->provisionTenant('acme', 'admin@acme.com');
        $admin = $this->loginAt('acme', 'admin@acme.com');

        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $url = $this->withToken($admin)->post($this->api('/settings/logo'), ['file' => new UploadedFile($path, 'logo.png', null, null, true)], $this->academy('acme') + ['Accept' => 'application/json'])
            ->assertOk()->json('data.logo_url');

        // Una imagen del navegador no lleva X-Tenant: igual se entrega, porque es pública
        $this->flushHeaders();
        $this->get(self::CENTRAL.$url)->assertOk();
        // Y el logo de los correos apunta a la API, no a la ruta de la academia
        Mail::fake();
        $this->withToken($admin)->postJson($this->api('/users'), [
            'first_name' => 'Ana', 'last_name' => 'Ríos', 'email' => 'ana@acme.com', 'password' => 'Temporal2026',
            'roles' => [$this->withToken($admin)->getJson($this->api('/roles'), $this->academy('acme'))->json('data.0.id')],
        ], $this->academy('acme'))->assertCreated();
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->logoUrl === self::CENTRAL.$url);
        $this->flushHeaders();

        // Un archivo que no existe responde igual que antes
        $this->get(self::CENTRAL.'/api/v1/public-files/01a10000-0000-7000-8000-000000000000')->assertNotFound();
    }

    public function test_online_payment_returns_to_the_academy_path_and_the_gateway_notices_reach_it(): void
    {
        Http::preventStrayRequests();
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $admin = $this->loginAt('acme', 'admin@acme.com');
        $staff = fn () => $this->withToken($admin);

        $course = $staff()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 120], $this->academy('acme'))->assertCreated()->json('data.id');
        $section = $staff()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'], $this->academy('acme'))->json('data.id');
        $staff()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'], $this->academy('acme'))->assertCreated();
        $staff()->postJson($this->api("/courses/{$course}/publish"), [], $this->academy('acme'))->assertOk();

        Http::fake(['https://api.mercadopago.com/users/me' => Http::response(['id' => 99, 'nickname' => 'ACADEMIAACME', 'site_id' => 'MPE'])]);
        $staff()->putJson($this->api('/settings/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave'], $this->academy('acme'))->assertOk();

        $this->createUser($tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->flushHeaders();
        $buyer = $this->loginAt('acme', 'ana@acme.com');
        $order = $this->withToken($buyer)->postJson($this->api('/orders'), ['course_id' => $course], $this->academy('acme'))->assertCreated()->json('data.id');
        $pay = fn (string $returnUrl) => $this->withToken($buyer)->postJson($this->api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => $returnUrl], $this->academy('acme'));

        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(['https://api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-1'], 201)]);

        // El regreso debe quedar dentro de la ruta de esta academia
        $pay(self::CENTRAL.'/a/otra/my-orders/'.$order)->assertStatus(422);
        $pay(self::CENTRAL.'/a/acmeotra/my-orders/'.$order)->assertStatus(422);
        $pay('https://otro-sitio.test/a/acme/my-orders/'.$order)->assertStatus(422);
        $pay(self::CENTRAL.'/a/acme/my-orders/'.$order)->assertCreated();

        // La pasarela avisa al dominio central, con la academia en la consulta
        Http::assertSent(fn (GatewayRequest $request) => $request['notification_url'] === self::CENTRAL.'/api/v1/payments/webhooks/mercadopago?tenant=acme'
            && $request['back_urls']['success'] === self::CENTRAL.'/a/acme/my-orders/'.$order);

        // Y ese aviso llega a la academia (sin pago que consultar, se responde igual que siempre)
        $this->flushHeaders();
        $this->postJson($this->api('/payments/webhooks/mercadopago?tenant=acme'), ['type' => 'other'])->assertOk()->assertJsonPath('data.received', true);
        $this->postJson($this->api('/payments/webhooks/mercadopago?tenant=no-existe'), ['type' => 'other'])->assertNotFound();
    }
}
