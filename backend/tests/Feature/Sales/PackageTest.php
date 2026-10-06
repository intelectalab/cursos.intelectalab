<?php

namespace Tests\Feature\Sales;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * S11 entrega 2: paquetes. Paquete → checkout → pago → orden → ítems → varias matrículas (FP-03).
 */
class PackageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $student;

    private string $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->studentId = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com'])->id;
        $this->student = $this->login('acme', 'ana@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function course(string $title, ?float $price, bool $publish = true): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'language' => 'es', 'price' => $price])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.'])->assertCreated();

        if ($publish) {
            $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();
        }

        return $course;
    }

    /**
     * @param  list<string>  $courses
     * @param  array<string, mixed>  $attributes
     */
    private function package(array $courses, array $attributes = []): TestResponse
    {
        return $this->withToken($this->admin)->postJson($this->api('/packages'), $attributes + [
            'title' => 'Ruta de análisis de datos', 'summary' => 'De Excel a Power BI.', 'price' => 300, 'status' => 'ACTIVE', 'course_ids' => $courses,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function buy(string $package, array $extra = [], ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->student)->postJson($this->api('/orders'), ['package_id' => $package] + $extra);
    }

    public function test_buying_a_package_creates_one_order_and_enrolls_in_every_course(): void
    {
        $excel = $this->course('Excel para finanzas', 100);
        $powerBi = $this->course('Power BI desde cero', 150);
        $sql = $this->course('SQL para analistas', 150);

        $package = $this->package([$excel, $powerBi, $sql])->assertCreated()
            ->assertJsonPath('data.list_price', '400.00')
            ->assertJsonPath('data.savings', '100.00')
            ->assertJsonPath('data.for_sale', true)
            ->json('data.id');

        // Visible en el catálogo público, sin sesión
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->api('/catalog/packages'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', '300.00')
            ->assertJsonCount(3, 'data.0.courses');

        $order = $this->buy($package, ['expected_total' => 300])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.package.title', 'Ruta de análisis de datos')
            ->assertJsonPath('data.total_amount', '300.00')
            ->assertJsonCount(3, 'data.items')
            ->json('data');

        // El precio del paquete se reparte entre sus cursos y suma exacto
        $this->assertSame(['75.00', '112.50', '112.50'], array_column($order['items'], 'total_amount'));

        // No se duplica la compra mientras espera pago
        $this->buy($package)->assertStatus(409)->assertJsonPath('code', 'ORDER_ALREADY_PENDING');

        $this->withToken($this->admin)->postJson($this->api("/orders/{$order['id']}/confirm-payment"), ['method' => 'TRANSFER'])
            ->assertOk()->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.items.2.enrolled', true);

        $this->withToken($this->student)->getJson($this->api('/me/courses'))->assertOk()->assertJsonCount(3, 'data');

        // Con todos los cursos ya no puede comprarlo
        $this->buy($package)->assertStatus(409)->assertJsonPath('code', 'ALREADY_ENROLLED');
        $this->withToken($this->admin)->getJson($this->api('/orders?search=sql'))->assertJsonCount(1, 'data');
    }

    public function test_owning_some_courses_still_allows_the_package_and_only_adds_the_missing_ones(): void
    {
        $excel = $this->course('Excel para finanzas', 100);
        $powerBi = $this->course('Power BI desde cero', 150);
        $package = $this->package([$excel, $powerBi], ['price' => 200])->assertCreated()->json('data.id');

        $this->withToken($this->admin)->postJson($this->api("/courses/{$excel}/enrollments"), ['student_ids' => [$this->studentId]])->assertCreated();

        // Antes de pagar, el comprador ve qué cursos ya son suyos
        $this->withToken($this->student)->getJson($this->api("/checkout/packages/{$package}"))
            ->assertOk()->assertJsonPath('data.owned_course_ids', [$excel]);

        // Paga el precio completo y queda matriculado solo en el que le faltaba
        $order = $this->buy($package, ['expected_total' => 200])->assertCreated()->json('data.id');
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE'])->assertOk();

        $this->withToken($this->student)->getJson($this->api('/me/courses'))->assertJsonCount(2, 'data');
        $this->withToken($this->admin)->getJson($this->api("/enrollments?course_id={$excel}"))->assertJsonPath('meta.total', 1);
    }

    public function test_a_coupon_applies_to_a_package_when_its_promotion_includes_it(): void
    {
        $excel = $this->course('Excel para finanzas', 100);
        $powerBi = $this->course('Power BI desde cero', 150);
        $package = $this->package([$excel, $powerBi], ['price' => 200])->assertCreated()->json('data.id');

        $promotion = fn (array $data) => $this->withToken($this->admin)->postJson($this->api('/promotions'), $data + [
            'name' => 'Promo', 'discount_type' => 'PERCENT', 'discount_value' => 10, 'once_per_customer' => false,
        ])->assertCreated();
        $promotion(['code' => 'SOLOCURSO', 'applies_to' => 'SELECTED', 'items' => [['type' => 'COURSE', 'id' => $excel]]]);
        $promotion(['code' => 'PAQUETE', 'applies_to' => 'SELECTED', 'items' => [['type' => 'PACKAGE', 'id' => $package]]]);

        $check = fn (string $code) => $this->withToken($this->student)->postJson($this->api('/checkout/validate-coupon'), ['code' => $code, 'package_id' => $package]);

        // Un cupón de uno de sus cursos no vale para el paquete
        $check('SOLOCURSO')->assertStatus(422)->assertJsonPath('details.reason', 'NOT_APPLICABLE');
        $check('PAQUETE')->assertOk()->assertJsonPath('data.subtotal_amount', '200.00')->assertJsonPath('data.total_amount', '180.00');

        $this->buy($package, ['coupon_code' => 'PAQUETE', 'expected_total' => 180])
            ->assertCreated()
            ->assertJsonPath('data.subtotal_amount', '200.00')
            ->assertJsonPath('data.discount_amount', '20.00')
            ->assertJsonPath('data.total_amount', '180.00');
    }

    public function test_a_package_leaves_the_catalog_when_it_or_one_of_its_courses_stops_being_for_sale(): void
    {
        $excel = $this->course('Excel para finanzas', 100);
        $powerBi = $this->course('Power BI desde cero', 150);
        $draft = $this->course('En preparación', 80, publish: false);
        $unpriced = $this->course('Solo matrícula manual', null);

        // Hacen falta al menos dos cursos, y para activarlo todos deben estar en venta
        $this->package([$excel])->assertStatus(422);
        $this->package([$excel, $draft])->assertStatus(422)->assertJsonStructure(['details' => ['course_ids']]);
        $this->package([$excel, $unpriced])->assertStatus(422);
        // Inactivo sí se puede ir armando
        $this->package([$excel, $draft], ['status' => 'INACTIVE'])->assertCreated()->assertJsonPath('data.for_sale', false);

        $package = $this->package([$excel, $powerBi])->assertCreated()->json('data.id');
        $catalog = fn () => $this->withToken($this->student)->getJson($this->api('/catalog/packages'))->json('data');
        $this->assertCount(1, $catalog());

        // La academia archiva uno de sus cursos: el paquete sale solo del catálogo
        $this->withToken($this->admin)->postJson($this->api("/courses/{$powerBi}/archive"))->assertOk();
        $this->assertCount(0, $catalog());
        $this->withToken($this->student)->getJson($this->api("/catalog/packages/{$package}"))->assertStatus(404);
        $this->buy($package)->assertStatus(409)->assertJsonPath('code', 'COURSE_NOT_FOR_SALE');
        $this->withToken($this->admin)->getJson($this->api("/packages/{$package}"))->assertJsonPath('data.for_sale', false)->assertJsonPath('data.courses.1.for_sale', false);
    }

    public function test_packages_respect_roles_and_organizations(): void
    {
        $package = $this->package([$this->course('Excel para finanzas', 100), $this->course('Power BI desde cero', 150)])->assertCreated()->json('data.id');

        $this->withToken($this->student)->getJson($this->api('/packages'))->assertStatus(403);

        $this->createUser($this->tenant, RoleCatalog::SALES, ['email' => 'vera@acme.com']);
        $sales = $this->login('acme', 'vera@acme.com');
        $this->withToken($sales)->getJson($this->api('/packages'))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($sales)->putJson($this->api("/packages/{$package}"), [])->assertStatus(403);

        $this->provisionTenant('globex', 'admin@globex.com');
        $globex = $this->login('globex', 'admin@globex.com');
        $url = fn (string $path) => $this->tenantUrl('globex', $path);

        $this->withToken($globex)->getJson($url('/packages'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($globex)->getJson($url("/packages/{$package}"))->assertStatus(404);
        $this->withToken($globex)->getJson($url('/catalog/packages'))->assertJsonCount(0, 'data');
        $this->withToken($globex)->getJson($url("/catalog/packages/{$package}"))->assertStatus(404);
    }
}
