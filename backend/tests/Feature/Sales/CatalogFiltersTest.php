<?php

namespace Tests\Feature\Sales;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buscador y filtros del catálogo público.
 */
class CatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $this->course(['title' => 'Excel para finanzas', 'summary' => 'Tablas dinámicas y modelos.', 'category' => 'Ofimática', 'level' => 'INTERMEDIATE', 'price' => 120]);
        $this->course(['title' => 'Introducción a la programación', 'category' => 'Tecnología', 'level' => 'BASIC', 'price' => 0]);
        $this->course(['title' => 'Power BI avanzado', 'category' => 'Tecnología', 'level' => 'ADVANCED', 'price' => 200]);
        // Sin precio no está en venta: nunca sale en el catálogo ni en sus filtros
        $this->course(['title' => 'Curso interno', 'category' => 'Privado', 'level' => 'BASIC']);
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function course(array $data): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $id = $admin()->postJson($this->api('/courses'), $data + ['language' => 'es'])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$id}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$id}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$id}/publish"))->assertOk();

        return $id;
    }

    public function test_catalog_is_searched_and_filtered_without_a_session(): void
    {
        $this->flushHeaders();
        $catalog = $this->api('/catalog/courses');

        // Sin filtros: todo lo que está en venta, en orden alfabético, con lo que se puede filtrar
        $this->getJson($catalog)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.title', 'Excel para finanzas')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.categories', ['Ofimática', 'Tecnología'])
            ->assertJsonPath('meta.levels', ['BASIC', 'INTERMEDIATE', 'ADVANCED'])
            ->assertJsonPath('meta.has_free', true)
            ->assertJsonPath('meta.has_paid', true);

        // Busca sin tildes ni mayúsculas, en título, resumen y categoría
        $this->getJson("{$catalog}?search=PROGRAMACION")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Introducción a la programación');
        $this->getJson("{$catalog}?search=dinamicas")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Excel para finanzas');
        $this->getJson("{$catalog}?search=tecnologia")->assertJsonCount(2, 'data');
        // Todas las palabras deben estar
        $this->getJson("{$catalog}?search=power+finanzas")->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 3);

        $this->getJson("{$catalog}?category=Tecnolog%C3%ADa")->assertJsonCount(2, 'data');
        $this->getJson("{$catalog}?level=ADVANCED")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Power BI avanzado');
        $this->getJson("{$catalog}?price=free")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Introducción a la programación');
        $this->getJson("{$catalog}?price=paid")->assertJsonCount(2, 'data');

        // Los filtros se combinan
        $this->getJson("{$catalog}?category=Tecnolog%C3%ADa&price=paid")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Power BI avanzado');

        $this->getJson("{$catalog}?level=EXPERTO")->assertStatus(422);
        $this->getJson("{$catalog}?price=barato")->assertStatus(422);
    }

    public function test_filters_use_the_published_version_not_the_draft(): void
    {
        $id = $this->course(['title' => 'Marketing digital', 'category' => 'Negocios', 'level' => 'BASIC', 'price' => 50]);

        // Se cambia la categoría en el borrador, sin publicar
        $this->withToken($this->admin)->putJson($this->api("/courses/{$id}"), [
            'title' => 'Marketing digital', 'category' => 'Ventas', 'level' => 'BASIC', 'price' => 50, 'language' => 'es',
        ])->assertOk();

        $this->flushHeaders();
        $this->getJson($this->api('/catalog/courses?category=Negocios'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('/catalog/courses?category=Ventas'))->assertJsonCount(0, 'data');
    }
}
