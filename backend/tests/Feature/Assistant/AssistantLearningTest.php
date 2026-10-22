<?php

namespace Tests\Feature\Assistant;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asistente, entrega 3: el buscador aprende sinónimos, los cursos que se
 * toman juntos y los pendientes nuevos de Inicio.
 */
class AssistantLearningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.trial_days' => 365]);
        $this->travelTo('2026-03-02 15:00:00');
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function course(string $title, ?int $price = 100): string
    {
        $admin = fn () => $this->withToken($this->admin);
        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'language' => 'es', 'price' => $price])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    private function buy(string $email, string $course, bool $refund = false): void
    {
        $this->flushHeaders();
        $order = $this->withToken($this->login('acme', $email))->postJson($this->api('/orders'), ['course_id' => $course])->assertCreated()->json('data.id');
        $this->flushHeaders()->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE'])->assertOk();

        if ($refund) {
            $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'Se inscribió por error.'])->assertOk();
        }
    }

    public function test_the_search_learns_synonyms_per_academy(): void
    {
        $excel = $this->course('Excel para finanzas');

        // «Hojas de cálculo» no está en el nombre: se aprende, y cada vez suma
        $learn = fn (string $term) => $this->withToken($this->admin)->postJson($this->api('/assistant/search-feedback'), ['term' => $term, 'target_type' => 'COURSE', 'target_id' => $excel]);
        $learn('Hojas de cálculo')->assertOk()->assertJsonPath('data.learned', true);
        $learn('hojas de calculo')->assertOk()->assertJsonPath('data.learned', true);
        // «Excel» ya está en el nombre: no hay nada que aprender
        $learn('excel')->assertOk()->assertJsonPath('data.learned', false);

        $this->withToken($this->admin)->getJson($this->api('/assistant/search-aliases'))
            ->assertOk()
            ->assertJsonPath('data.hojas de calculo.0', $excel)
            ->assertJsonMissingPath('data.excel');
        $this->assertDatabaseHas('search_aliases', ['term' => 'hojas de calculo', 'target_id' => $excel, 'hits' => 2]);

        // Un curso que no existe no se aprende
        $this->withToken($this->admin)->postJson($this->api('/assistant/search-feedback'), ['term' => 'algo', 'target_type' => 'COURSE', 'target_id' => '01a00000-0000-7000-8000-000000000000'])->assertNotFound();

        // Otra academia no ve lo que aprendió esta
        $this->provisionTenant('beta', 'admin@beta.com');
        $this->flushHeaders()->withToken($this->login('beta', 'admin@beta.com'))->getJson($this->tenantUrl('beta', '/assistant/search-aliases'))->assertJsonPath('data', []);
    }

    public function test_companions_need_two_people_in_common_and_leave_out_refunds(): void
    {
        $excel = $this->course('Excel para finanzas');
        $powerBi = $this->course('Power BI', 250);
        $word = $this->course('Word básico', 80);
        $draft = $this->course('Access', 90);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$draft}/archive"))->assertOk();
        foreach (['ana', 'luis', 'rosa', 'eva'] as $name) {
            $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => "{$name}@acme.com"]);
        }

        // Ana y Luis llevaron Excel y Power BI; Rosa, Excel y Word; Eva compró Word con Excel pero le devolvieron Word
        foreach (['ana', 'luis'] as $name) {
            $this->buy("{$name}@acme.com", $excel);
            $this->buy("{$name}@acme.com", $powerBi);
        }
        $this->buy('rosa@acme.com', $excel);
        $this->buy('rosa@acme.com', $word);
        $this->buy('eva@acme.com', $excel);
        $this->buy('eva@acme.com', $word, refund: true);

        $this->flushHeaders()->getJson($this->api("/catalog/courses/{$excel}/companions"))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Power BI')
            ->assertJsonPath('data.0.people', 2);

        $this->withToken($this->admin)->getJson($this->api('/assistant/companions?'.http_build_query(['ids' => [$powerBi]])))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Excel para finanzas');

        // Un curso archivado no tiene sugerencias públicas
        $this->flushHeaders()->getJson($this->api("/catalog/courses/{$draft}/companions"))->assertJsonPath('data', []);
    }

    public function test_home_pending_items_include_expiring_and_inactive_enrollments(): void
    {
        $excel = $this->course('Excel para finanzas', null);
        $ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$excel}/enrollments"), ['student_ids' => [$ana->id]])->assertCreated();

        $this->travelTo('2026-03-23 15:00:00');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($this->admin)->getJson($this->api('/alerts/summary'))
            ->assertOk()
            ->assertJsonPath('data.inactive_students', 1)
            ->assertJsonPath('data.enrollments_expiring', 0);

        // El docente no matricula: esas cifras no le llegan
        $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'rosa@acme.com']);
        $this->flushHeaders()->withToken($this->login('acme', 'rosa@acme.com'))->getJson($this->api('/alerts/summary'))
            ->assertJsonPath('data.inactive_students', null);
    }

    public function test_wrong_synonyms_can_be_forgotten(): void
    {
        $excel = $this->course('Excel para finanzas');
        $this->withToken($this->admin)->postJson($this->api('/assistant/search-feedback'), ['term' => 'cocina', 'target_type' => 'COURSE', 'target_id' => $excel])->assertOk();

        $alias = $this->withToken($this->admin)->getJson($this->api('/assistant/search-aliases/learned'))
            ->assertOk()
            ->assertJsonPath('data.0.term', 'cocina')
            ->assertJsonPath('data.0.title', 'Excel para finanzas')
            ->json('data.0.id');

        $this->withToken($this->admin)->deleteJson($this->api("/assistant/search-aliases/{$alias}"))->assertOk()->assertJsonPath('data', []);
        $this->withToken($this->admin)->getJson($this->api('/assistant/search-aliases'))->assertJsonPath('data', []);

        // Un alumno puede enseñar sinónimos al buscar, pero no revisarlos ni borrarlos
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->flushHeaders()->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api('/assistant/search-aliases/learned'))->assertForbidden();
    }

    public function test_packages_bought_by_the_same_people_are_suggested_too(): void
    {
        $excel = $this->course('Excel para finanzas');
        $powerBi = $this->course('Power BI', 250);
        $word = $this->course('Word básico', 80);
        $package = $this->withToken($this->admin)->postJson($this->api('/packages'), ['title' => 'Ruta de datos', 'price' => 300, 'status' => 'ACTIVE', 'course_ids' => [$powerBi, $word]])->assertCreated()->json('data.id');

        foreach (['ana', 'luis'] as $name) {
            $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => "{$name}@acme.com"]);
            $this->buy("{$name}@acme.com", $excel);
            $this->flushHeaders();
            $order = $this->withToken($this->login('acme', "{$name}@acme.com"))->postJson($this->api('/orders'), ['package_id' => $package])->assertCreated()->json('data.id');
            $this->flushHeaders()->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE'])->assertOk();
        }

        $this->flushHeaders()->getJson($this->api("/catalog/courses/{$excel}/companions"))
            ->assertOk()
            ->assertJsonFragment(['type' => 'PACKAGE', 'title' => 'Ruta de datos', 'people' => 2]);

        // Para quien mira Power BI, el paquete que ya lo trae no es una sugerencia nueva
        $this->getJson($this->api("/catalog/courses/{$powerBi}/companions"))->assertJsonMissing(['type' => 'PACKAGE']);
    }
}
