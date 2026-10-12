<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Limpieza de lo que dejan los recorridos automáticos en una academia de
 * pruebas: borra lo marcado «E2E» con todo lo que cuelga, y nada más.
 */
class CleanE2eDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_counts_without_force_and_deletes_only_e2e_data_with_it(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $admin = $this->login('acme', 'admin@acme.com');
        $api = fn (string $path) => $this->tenantUrl('acme', $path);

        $course = function (string $title) use ($admin, $api): string {
            $id = $this->withToken($admin)->postJson($api('/courses'), ['title' => $title, 'language' => 'es', 'price' => 100])->assertCreated()->json('data.id');
            $section = $this->withToken($admin)->postJson($api("/courses/{$id}/sections"), ['title' => 'Módulo 1'])->json('data.id');
            $this->withToken($admin)->postJson($api("/courses/{$id}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
            $this->withToken($admin)->postJson($api("/courses/{$id}/publish"))->assertOk();

            return $id;
        };

        // Un curso real con su alumno, y lo que deja un recorrido: curso, comprador, orden pagada y matrícula
        $real = $course('Excel para finanzas');
        $ana = $this->createUser($tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->withToken($admin)->postJson($api("/courses/{$real}/enrollments"), ['student_ids' => [$ana->id]])->assertCreated();

        $e2e = $course('E2E Compra ABC123');
        $this->createUser($tenant, RoleCatalog::STUDENT, ['email' => 'e2e.abc123@prueba.test']);
        $buyer = $this->login('acme', 'e2e.abc123@prueba.test');
        $order = $this->withToken($buyer)->postJson($api('/orders'), ['course_id' => $e2e])->assertCreated()->json('data.id');
        $this->withToken($admin)->postJson($api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE'])->assertOk();

        $titles = fn () => collect($this->withToken($this->login('acme', 'admin@acme.com'))->getJson($api('/courses'))->json('data'))->pluck('title')->sort()->values()->all();

        // Sin --force: dice qué borraría y no toca nada
        $this->artisan('demo:clean-e2e', ['tenant' => 'acme'])->expectsOutputToContain('No se borró nada')->assertSuccessful();
        $this->assertSame(['E2E Compra ABC123', 'Excel para finanzas'], $titles());

        $this->artisan('demo:clean-e2e', ['tenant' => 'acme', '--force' => true])->expectsOutputToContain('filas borradas')->assertSuccessful();

        // Quedó solo lo real: el curso, su alumno y su matrícula
        $this->assertSame(['Excel para finanzas'], $titles());
        $admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($admin)->getJson($api('/orders'))->assertJsonPath('meta.total', 0);
        $this->withToken($admin)->getJson($api('/enrollments'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.student.email', 'ana@acme.com');
        // Quien inició sesión figura en la auditoría, que no se borra: su cuenta se conserva, desactivada y sin nada más
        $this->withToken($admin)->getJson($api('/users?search=e2e'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'INACTIVE');

        // Una academia que no existe
        $this->artisan('demo:clean-e2e', ['tenant' => 'ninguna'])->assertFailed();
    }
}
