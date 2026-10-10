<?php

namespace Tests\Feature\Tenancy;

use App\Shared\Http\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * La documentación técnica se genera del propio sistema: no puede dejar
 * fuera una ruta, un código de error ni una tabla.
 */
class TechnicalDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_generated_documents_cover_every_route_error_code_and_table(): void
    {
        $directory = storage_path('framework/testing/docs-'.uniqid());

        try {
            $this->artisan('docs:technical', ['--path' => $directory])->assertSuccessful();

            $api = json_decode((string) file_get_contents("{$directory}/openapi.json"), true);

            // Cada ruta de la API está en el contrato, con su sesión y su permiso
            foreach (Route::getRoutes() as $route) {
                if (str_starts_with($route->uri(), 'api/v1')) {
                    $this->assertArrayHasKey('/'.ltrim(substr($route->uri(), strlen('api/v1')), '/'), $api['paths']);
                }
            }

            $order = $api['paths']['/orders']['post'];
            $this->assertSame(['orders.create'], $order['x-permission']);
            $this->assertSame([['bearerAuth' => []]], $order['security']);
            $this->assertSame('uuid', $order['requestBody']['content']['application/json']['schema']['properties']['course_id']['format']);
            $this->assertSame([], $api['paths']['/auth/login']['post']['security']);

            $errors = (string) file_get_contents("{$directory}/catalogo-de-errores.md");

            foreach (ErrorCode::cases() as $code) {
                $this->assertStringContainsString("`{$code->value}`", $errors);
            }

            $dictionary = (string) file_get_contents("{$directory}/diccionario-de-datos.md");

            foreach (['tenants', 'users', 'orders', 'payments', 'plan_orders', 'audit_events'] as $table) {
                $this->assertStringContainsString("### `{$table}`", $dictionary);
            }

            // Una clave foránea y un comentario de migración llegan al diccionario
            $this->assertMatchesRegularExpression('/\| `order_id` \| UUID \| FK orders\.id \| Sí \|/', $dictionary);
            $this->assertStringContainsString('Aislada por academia.', $dictionary);

            $this->assertStringContainsString('`STATUS_REFUNDED` | `REFUNDED`', (string) file_get_contents("{$directory}/catalogo-de-estados.md"));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
