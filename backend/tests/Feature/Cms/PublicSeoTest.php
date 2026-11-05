<?php

namespace Tests\Feature\Cms;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Páginas públicas en buscadores (S26): el servidor entrega la página de
 * entrada de la aplicación con el título, la descripción, la imagen y los
 * datos estructurados de cada página pública, más el robots.txt y el mapa del
 * sitio de cada academia. La que no quiere aparecer queda fuera.
 */
class PublicSeoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $course;

    private string $index;

    protected function setUp(): void
    {
        parent::setUp();

        // La página de entrada compilada de la aplicación web
        $this->index = storage_path('framework/testing/index.html');
        File::ensureDirectoryExists(dirname($this->index));
        File::put($this->index, "<!doctype html>\n<html lang=\"es\">\n  <head>\n    <meta charset=\"UTF-8\" />\n    <title>IntelectaLab</title>\n  </head>\n  <body>\n    <div id=\"root\"></div>\n    <script type=\"module\" src=\"/assets/index.js\"></script>\n  </body>\n</html>\n");
        config(['tenancy.web_index' => $this->index]);

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->course = $this->publishedCourse('Excel para finanzas <avanzado>', 150);
    }

    protected function tearDown(): void
    {
        File::delete($this->index);

        parent::tearDown();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function publishedCourse(string $title, float $price): string
    {
        $admin = fn () => $this->withToken($this->admin);
        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'summary' => 'Tablas dinámicas y flujo de caja para tu empresa.', 'language' => 'es', 'price' => $price])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Fórmulas financieras'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    public function test_a_course_page_carries_its_title_description_and_course_data_for_search_engines(): void
    {
        $html = $this->get("http://acme.localhost/catalog/{$this->course}")->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeaderMissing('X-Robots-Tag')
            ->getContent();

        // El título del curso reemplaza al de la aplicación y llega escapado
        $this->assertStringContainsString('<title>Excel para finanzas &lt;avanzado&gt; · Organización acme</title>', $html);
        $this->assertStringNotContainsString('<title>IntelectaLab</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Tablas dinámicas y flujo de caja para tu empresa.">', $html);
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"http://acme.localhost/catalog/{$this->course}\">", $html);
        $this->assertStringContainsString('<meta property="og:type" content="product">', $html);

        // Datos estructurados de schema.org: el «<» del título no puede cerrar la etiqueta
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $schema);
        $this->assertStringNotContainsString('<avanzado>', $schema[1]);
        $data = json_decode($schema[1], true);
        $this->assertSame('Course', $data['@type']);
        $this->assertSame('Excel para finanzas <avanzado>', $data['name']);
        $this->assertSame(['@type' => 'Offer', 'price' => '150.00', 'priceCurrency' => 'PEN', 'category' => 'Paid', 'availability' => 'https://schema.org/InStock', 'url' => "http://acme.localhost/catalog/{$this->course}"], $data['offers']);

        // El temario, para quien no ejecuta la aplicación; la aplicación sigue igual
        $this->assertStringContainsString('<noscript><h1>Excel para finanzas &lt;avanzado&gt;</h1>', $html);
        $this->assertStringContainsString('<li>Fórmulas financieras</li>', $html);
        $this->assertStringContainsString('<div id="root"></div>', $html);
        $this->assertStringContainsString('<script type="module" src="/assets/index.js"></script>', $html);
    }

    public function test_the_home_and_catalog_list_the_courses_and_an_unknown_course_gets_the_plain_page(): void
    {
        $home = $this->get('http://acme.localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Organización acme</title>', $home);
        $this->assertStringContainsString('"@type":"EducationalOrganization"', $home);
        $this->assertStringContainsString("href=\"http://acme.localhost/catalog/{$this->course}\"", $home);

        $catalog = $this->get('http://acme.localhost/catalog')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Cursos · Organización acme</title>', $catalog);
        $this->assertStringContainsString('1 curso disponible', $catalog);

        // Un curso que no existe (o en borrador): la aplicación muestra su aviso, sin datos inventados
        $missing = $this->get('http://acme.localhost/catalog/'.str_repeat('0', 8).'-0000-0000-0000-'.str_repeat('0', 12))->assertOk()->getContent();
        $this->assertStringContainsString('<title>IntelectaLab</title>', $missing);
        $this->assertStringNotContainsString('og:title', $missing);
    }

    public function test_in_path_mode_the_academy_comes_from_the_path_and_robots_lists_each_sitemap(): void
    {
        config(['tenancy.mode' => 'path', 'tenancy.central_domain' => 'cursos.test']);
        $this->provisionTenant('beta', 'admin@beta.com');

        $html = $this->get("http://cursos.test/a/acme/catalog/{$this->course}")->assertOk()->getContent();
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"http://cursos.test/a/acme/catalog/{$this->course}\">", $html);
        // Otra academia no sirve los cursos de la primera
        $this->assertStringContainsString('<title>IntelectaLab</title>', $this->get("http://cursos.test/a/beta/catalog/{$this->course}")->assertOk()->getContent());

        $sitemap = $this->get('http://cursos.test/a/acme/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>http://cursos.test/a/acme/</loc>', $sitemap);
        $this->assertStringContainsString("<loc>http://cursos.test/a/acme/catalog/{$this->course}</loc>", $sitemap);

        $robots = $this->get('http://cursos.test/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /a/*/login', $robots);
        $this->assertStringContainsString('Sitemap: http://cursos.test/a/acme/sitemap.xml', $robots);
        $this->assertStringContainsString('Sitemap: http://cursos.test/a/beta/sitemap.xml', $robots);

        // La portada de la plataforma
        $this->assertStringContainsString('Cursos virtuales para tu academia', $this->get('http://cursos.test/')->assertOk()->getContent());
    }

    public function test_an_academy_that_turns_off_search_engines_is_left_out(): void
    {
        $settings = $this->withToken($this->admin)->getJson($this->api('/settings'))->assertOk()->assertJsonPath('data.search_indexing', true)->json('data');
        $this->withToken($this->admin)->putJson($this->api('/settings'), ['search_indexing' => false] + $settings)->assertOk()->assertJsonPath('data.search_indexing', false);

        $this->get("http://acme.localhost/catalog/{$this->course}")->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSee('<meta name="robots" content="noindex">', false);
        $this->assertStringContainsString("Disallow: /\n", $this->get('http://acme.localhost/robots.txt')->assertOk()->getContent());
        $this->get('http://acme.localhost/sitemap.xml')->assertNotFound();
    }

    public function test_a_suspended_academy_gets_the_plain_page(): void
    {
        $this->tenant->forceFill(['status' => 'SUSPENDED'])->save();

        $html = $this->get('http://acme.localhost/catalog')->assertOk()->getContent();
        $this->assertStringContainsString('<title>IntelectaLab</title>', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
    }
}
