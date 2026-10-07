<?php

namespace Tests\Feature\Performance;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S12: los listados no deben hacer más consultas cuando tienen más filas (N+1).
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_lazy_loading_is_forbidden_outside_production(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
    }

    public function test_listings_use_the_same_number_of_queries_with_more_rows(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');
        $api = fn (string $path) => $this->tenantUrl('acme', $path);

        $grow = function () use ($tenant, $token, $api): void {
            foreach (range(1, 3) as $ignored) {
                $student = $this->createUser($tenant, RoleCatalog::STUDENT);
                $course = $this->withToken($token)->postJson($api('/courses'), ['title' => 'Curso '.fake()->unique()->numerify('#####'), 'language' => 'es', 'price' => 50])
                    ->assertCreated()->json('data.id');
                $section = $this->withToken($token)->postJson($api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
                $this->withToken($token)->postJson($api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Lección', 'body' => 'Texto'])->assertCreated();
                $this->withToken($token)->postJson($api("/courses/{$course}/publish"))->assertOk();
                $this->withToken($token)->postJson($api("/courses/{$course}/enrollments"), ['student_ids' => [$student->id]])->assertSuccessful();
                // Otra persona lo compra: queda una orden pendiente
                $buyer = $this->createUser($tenant, RoleCatalog::STUDENT);
                $this->withToken($this->login('acme', $buyer->email))->postJson($api('/orders'), ['course_id' => $course])->assertSuccessful();
            }
        };

        $listings = ['/users', '/roles', '/courses', '/enrollments', '/orders', '/catalog/courses', '/audit-events', '/certificates', '/reports/overview'];

        $grow();
        $before = array_map(fn (string $path) => $this->queriesOf($token, $api($path)), array_combine($listings, $listings));

        $grow();
        $after = array_map(fn (string $path) => $this->queriesOf($token, $api($path)), array_combine($listings, $listings));

        $this->assertSame($before, $after);
    }

    private function queriesOf(string $token, string $url): int
    {
        // La primera petición de una sesión renueva su vencimiento: no se cuenta
        $this->withToken($token)->getJson($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withToken($token)->getJson($url)->assertOk();

        // La sesión anota su último uso una vez por segundo: esa escritura depende del reloj, no del listado
        $count = count(array_filter(DB::getQueryLog(), fn (array $query) => ! str_starts_with($query['query'], 'update "personal_access_tokens"')));
        DB::disableQueryLog();

        return $count;
    }
}
