<?php

namespace Tests\Feature\Cms;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Permission;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * S10 entrega 3: páginas. Borrador → edición → publicación → versión (FP-19, RN-017).
 */
class PageTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    // PNG de 1×1
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function page(string $title = 'Términos y condiciones', string $slug = 'terminos'): string
    {
        return $this->withToken($this->admin)->postJson($this->api('/pages'), ['title' => $title, 'slug' => $slug])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $extra
     */
    private function save(string $page, array $content, array $extra = [], ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->admin)->putJson($this->api("/pages/{$page}"), $extra + [
            'title' => 'Términos y condiciones', 'slug' => 'terminos', 'content' => $content, 'show_in_footer' => true,
        ]);
    }

    /**
     * Lo que ve un visitante sin sesión.
     */
    private function visit(string $path, string $slug = 'acme'): TestResponse
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->getJson($this->tenantUrl($slug, $path));
    }

    public function test_a_page_is_drafted_published_and_edited_without_touching_what_the_public_sees(): void
    {
        $page = $this->page();

        // Borrador: no existe para el público, y vacío no se publica
        $this->visit('/site/pages/terminos')->assertStatus(404);
        $this->withToken($this->admin)->postJson($this->api("/pages/{$page}/publish"))
            ->assertStatus(409)->assertJsonPath('code', 'PAGE_NOT_PUBLISHABLE');

        $this->save($page, $this->doc('Primera versión.'))->assertOk()->assertJsonPath('data.status', 'DRAFT');
        $this->withToken($this->admin)->postJson($this->api("/pages/{$page}/publish"))
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.pending_changes', false)
            ->assertJsonPath('data.versions.0.version', 1)
            ->assertJsonPath('data.versions.0.published_by', 'Ada Admin');

        $this->visit('/site/pages/terminos')->assertOk()
            ->assertJsonPath('data.title', 'Términos y condiciones')
            ->assertJsonPath('data.content.content.0.content.0.text', 'Primera versión.');
        $this->visit('/site/pages')->assertOk()->assertJsonPath('data.0.slug', 'terminos');

        // Se sigue editando: el público ve lo publicado hasta que se publique de nuevo
        $this->travel(1)->minutes();
        $admin = $this->login('acme', 'admin@acme.com');
        $this->save($page, $this->doc('Segunda versión.'), ['title' => 'Términos del servicio'], $admin)
            ->assertOk()->assertJsonPath('data.pending_changes', true);
        $this->visit('/site/pages/terminos')
            ->assertJsonPath('data.title', 'Términos y condiciones')
            ->assertJsonPath('data.content.content.0.content.0.text', 'Primera versión.');

        // Guardar sin cambios no marca nada como pendiente de más
        $this->travel(1)->minutes();
        $this->withToken($admin)->postJson($this->api("/pages/{$page}/publish"))->assertOk()->assertJsonCount(2, 'data.versions');
        $this->travel(1)->minutes();
        $this->save($page, $this->doc('Segunda versión.'), ['title' => 'Términos del servicio'], $admin)->assertJsonPath('data.pending_changes', false);

        $this->visit('/site/pages/terminos')
            ->assertJsonPath('data.title', 'Términos del servicio')
            ->assertJsonPath('data.content.content.0.content.0.text', 'Segunda versión.');

        // Retirada deja de verse y sale del pie; conserva sus versiones
        $this->withToken($admin)->postJson($this->api("/pages/{$page}/unpublish"))
            ->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonCount(2, 'data.versions');
        $this->visit('/site/pages/terminos')->assertStatus(404);
        $this->visit('/site/pages')->assertJsonCount(0, 'data');

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(2, AuditEvent::query()->where('action', 'pages.published')->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'pages.unpublished')->count());
        });
    }

    public function test_content_keeps_only_safe_nodes_links_and_own_images(): void
    {
        $page = $this->page();
        $image = '/api/v1/public-files/01a10f0f-f109-7092-8f66-7da6ea2b4f7c';

        $saved = $this->save($page, ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1, 'onclick' => 'alert(1)'], 'content' => [['type' => 'text', 'text' => 'Título']]],
            ['type' => 'paragraph', 'attrs' => ['style' => 'position:fixed'], 'content' => [
                ['type' => 'text', 'text' => 'Seguro', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://ejemplo.pe', 'onclick' => 'x()']]]],
                ['type' => 'text', 'text' => 'Trampa', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']], ['type' => 'textStyle', 'attrs' => ['color' => 'red']]]],
                ['type' => 'text', 'text' => 'Interno', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/catalog']]]],
                ['type' => 'text', 'text' => 'Protocolo', 'marks' => [['type' => 'link', 'attrs' => ['href' => '//malo.example']]]],
            ]],
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
            ['type' => 'iframe', 'attrs' => ['src' => 'https://malo.example']],
            ['type' => 'image', 'attrs' => ['src' => 'https://malo.example/rastreo.png']],
            ['type' => 'image', 'attrs' => ['src' => $image, 'alt' => 'Logo', 'onerror' => 'alert(1)']],
        ]])->assertOk()->json('data.content.content');

        $this->assertSame([
            ['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'Título']], 'attrs' => ['level' => 2]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Seguro', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://ejemplo.pe']]]],
                ['type' => 'text', 'text' => 'Trampa'],
                ['type' => 'text', 'text' => 'Interno', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/catalog']]]],
                ['type' => 'text', 'text' => 'Protocolo'],
            ]],
            ['type' => 'image', 'attrs' => ['src' => $image, 'alt' => 'Logo']],
        ], $saved);
    }

    public function test_addresses_are_unique_well_formed_and_images_are_validated(): void
    {
        $this->page();
        $create = fn (string $slug) => $this->withToken($this->admin)->postJson($this->api('/pages'), ['title' => 'Otra', 'slug' => $slug]);

        $create('terminos')->assertStatus(422);
        $create('Con Espacios')->assertStatus(422);
        $create('api')->assertStatus(422);
        $other = $create('preguntas-frecuentes')->assertCreated()->json('data.id');

        // No puede tomar la dirección de otra página
        $this->save($other, $this->doc('Hola'))->assertStatus(422);

        $upload = function (string $name, string $content): TestResponse {
            $path = tempnam(sys_get_temp_dir(), 'upl');
            file_put_contents($path, $content);

            return $this->withToken($this->admin)->post($this->api('/pages/media'), ['file' => new UploadedFile($path, $name, null, null, true)], self::JSON);
        };

        $upload('logo.png', '<?php echo 1;')->assertStatus(422);
        $url = $upload('logo.png', base64_decode(self::PNG))->assertCreated()->json('data.url');

        $this->assertMatchesRegularExpression('#^/api/v1/public-files/[0-9a-f-]{36}$#', $url);
        // La imagen es pública: se ve sin sesión
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->get($this->tenantUrl('acme', substr($url, strlen('/api/v1'))))->assertOk();

        // Publicada no se elimina; retirada sí
        $this->save($other, $this->doc('Hola'), ['slug' => 'preguntas-frecuentes', 'title' => 'Preguntas'], $this->login('acme', 'admin@acme.com'))->assertOk();
        $admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($admin)->postJson($this->api("/pages/{$other}/publish"))->assertOk();
        $this->withToken($admin)->deleteJson($this->api("/pages/{$other}"))->assertStatus(409);
        $this->withToken($admin)->postJson($this->api("/pages/{$other}/unpublish"))->assertOk();
        $this->withToken($admin)->deleteJson($this->api("/pages/{$other}"))->assertOk();
        $this->withToken($admin)->getJson($this->api('/pages'))->assertJsonCount(1, 'data');
    }

    public function test_editing_and_publishing_are_separate_permissions(): void
    {
        $page = $this->page();

        // Un rol propio que solo redacta
        $editor = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'eva@acme.com']);
        app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($editor) {
            $role = Role::create(['code' => 'EDITOR', 'name' => 'Redactor', 'is_system' => false]);
            $role->permissions()->attach(Permission::query()->whereIn('code', ['cms.read', 'cms.update'])->pluck('id'));
            $editor->roles()->sync([$role->id]);
        });
        $token = $this->login('acme', 'eva@acme.com');

        $this->save($page, $this->doc('Borrador del redactor.'), [], $token)->assertOk();
        $this->withToken($token)->postJson($this->api("/pages/{$page}/publish"))
            ->assertStatus(403)->assertJsonPath('details.permission', 'cms.publish');
        $this->visit('/site/pages/terminos')->assertStatus(404);

        // Sin permisos de páginas no se ve ni la lista
        $this->createUser($this->tenant, RoleCatalog::SUPPORT, ['email' => 'sol@acme.com']);
        $this->withToken($this->login('acme', 'sol@acme.com'))->getJson($this->api('/pages'))->assertStatus(403);
    }

    public function test_a_draft_goes_through_review_before_someone_else_publishes_it(): void
    {
        $page = $this->page();

        $editor = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'eva@acme.com', 'first_name' => 'Eva', 'last_name' => 'Redacta']);
        app(TenantContext::class)->runForTenant($this->tenant->id, function () use ($editor) {
            $role = Role::create(['code' => 'EDITOR', 'name' => 'Redactor', 'is_system' => false]);
            $role->permissions()->attach(Permission::query()->whereIn('code', ['cms.read', 'cms.update'])->pluck('id'));
            $editor->roles()->sync([$role->id]);
        });
        $eva = $this->login('acme', 'eva@acme.com');
        $submit = fn () => $this->withToken($eva)->postJson($this->api("/pages/{$page}/submit-review"));
        $return = fn (string $token, array $body) => $this->withToken($token)->postJson($this->api("/pages/{$page}/request-changes"), $body);

        // Una página vacía no se envía
        $submit()->assertStatus(409)->assertJsonPath('code', 'PAGE_NOT_PUBLISHABLE');

        $this->save($page, $this->doc('Primera redacción.'), [], $eva)->assertOk();
        $submit()->assertOk()
            ->assertJsonPath('data.review_status', 'IN_REVIEW')
            ->assertJsonPath('data.review.requested_by', 'Eva Redacta');
        $this->withToken($this->admin)->getJson($this->api('/pages'))->assertJsonPath('data.0.review_status', 'IN_REVIEW');

        // Devolverla es de quien publica, y exige decir qué corregir
        $return($eva, ['comment' => 'Me la devuelvo'])->assertStatus(403)->assertJsonPath('details.permission', 'cms.publish');
        $return($this->admin, [])->assertStatus(422);
        $return($this->admin, ['comment' => 'Falta el plazo de devolución.'])->assertOk()
            ->assertJsonPath('data.review_status', 'CHANGES_REQUESTED')
            ->assertJsonPath('data.review.comment', 'Falta el plazo de devolución.');
        $return($this->admin, ['comment' => 'Otra vez'])->assertStatus(409);
        $this->visit('/site/pages/terminos')->assertStatus(404);

        // Corrige y la envía de nuevo; si la toca después, hay que volver a enviarla
        $this->save($page, $this->doc('Segunda redacción, con plazo.'), [], $eva)->assertOk()->assertJsonPath('data.review_status', 'CHANGES_REQUESTED');
        $submit()->assertOk()->assertJsonPath('data.review_status', 'IN_REVIEW')->assertJsonPath('data.review.comment', null);
        $this->save($page, $this->doc('Tercera redacción.'), [], $eva)->assertOk()->assertJsonPath('data.review_status', null);
        $submit()->assertOk();

        // Publicar aprueba: la revisión se cierra y el público ve lo enviado
        $this->withToken($this->admin)->postJson($this->api("/pages/{$page}/publish"))->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.review_status', null);
        $this->visit('/site/pages/terminos')->assertOk();

        // Sin cambios nuevos no hay nada que revisar
        $this->withToken($this->login('acme', 'eva@acme.com'))->postJson($this->api("/pages/{$page}/submit-review"))->assertStatus(409);

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(3, AuditEvent::query()->where('action', 'pages.review_requested')->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'pages.changes_requested')->count());
        });
    }

    public function test_pages_are_isolated_between_organizations(): void
    {
        $page = $this->page();
        $this->save($page, $this->doc('Solo de Acme.'))->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/pages/{$page}/publish"))->assertOk();

        $this->provisionTenant('globex', 'admin@globex.com');
        $globex = $this->login('globex', 'admin@globex.com');

        $this->withToken($globex)->getJson($this->tenantUrl('globex', '/pages'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($globex)->getJson($this->tenantUrl('globex', "/pages/{$page}"))->assertStatus(404);
        $this->withToken($globex)->postJson($this->tenantUrl('globex', "/pages/{$page}/unpublish"))->assertStatus(404);
        // La misma dirección puede existir en otra organización
        $this->withToken($globex)->postJson($this->tenantUrl('globex', '/pages'), ['title' => 'Términos', 'slug' => 'terminos'])->assertCreated();

        $this->visit('/site/pages/terminos', 'globex')->assertStatus(404);
        $this->visit('/site/pages/terminos')->assertOk();
    }

    public function test_each_page_chooses_where_its_link_appears_and_its_order(): void
    {
        $faq = $this->page('Preguntas frecuentes', 'preguntas');
        $this->save($faq, $this->doc('Respuestas.'), ['title' => 'Preguntas frecuentes', 'slug' => 'preguntas', 'show_in_footer' => false, 'show_in_header' => true, 'show_in_auth' => true, 'show_in_student_menu' => true, 'menu_order' => 1])
            ->assertOk()
            ->assertJsonPath('data.show_in_header', true)
            ->assertJsonPath('data.show_in_student_menu', true)
            ->assertJsonPath('data.menu_order', 1);
        $this->withToken($this->admin)->postJson($this->api("/pages/{$faq}/publish"))->assertOk();

        $terms = $this->page();
        $this->save($terms, $this->doc('Condiciones.'), ['menu_order' => 2])->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/pages/{$terms}/publish"))->assertOk();

        // Sin ningún lugar marcado, la página se publica pero no se enlaza
        $hidden = $this->page('Oculta', 'oculta');
        $this->save($hidden, $this->doc('Solo con su enlace.'), ['title' => 'Oculta', 'slug' => 'oculta', 'show_in_footer' => false])->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/pages/{$hidden}/publish"))->assertOk();

        $this->visit('/site/pages')->assertOk()
            ->assertJsonCount(2, 'data')
            // Por orden: primero «Preguntas frecuentes» (1), después «Términos» (2)
            ->assertJsonPath('data.0.slug', 'preguntas')
            ->assertJsonPath('data.0.footer', false)
            ->assertJsonPath('data.0.header', true)
            ->assertJsonPath('data.0.auth', true)
            ->assertJsonPath('data.0.student_menu', true)
            ->assertJsonPath('data.1.slug', 'terminos')
            ->assertJsonPath('data.1.footer', true);
        $this->visit('/site/pages/oculta')->assertOk();

        $this->save($terms, $this->doc('Condiciones.'), ['menu_order' => 1000])->assertStatus(422);
    }
}
