<?php

namespace Tests\Feature\Promotions;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * S11 entrega 1: promociones y cupones. Regla → cupón → validar → descontar → uso (FP-13, RN-008).
 */
class CouponTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com', 'first_name' => 'Ana', 'last_name' => 'Torres']);
        $this->student = $this->login('acme', 'ana@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function course(string $title = 'Power BI desde cero', float $price = 150): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'language' => 'es', 'price' => $price])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function promotion(array $attributes = []): TestResponse
    {
        return $this->withToken($this->admin)->postJson($this->api('/promotions'), $attributes + [
            'name' => 'Campaña de verano', 'code' => 'VERANO', 'discount_type' => 'PERCENT', 'discount_value' => 20,
            'once_per_customer' => true, 'applies_to' => 'ALL',
        ]);
    }

    private function check(string $code, string $course, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->student)->postJson($this->api('/checkout/validate-coupon'), ['code' => $code, 'course_id' => $course]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function buy(string $course, array $extra = [], ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->student)->postJson($this->api('/orders'), ['course_id' => $course] + $extra);
    }

    private function otherStudent(string $email = 'beto@acme.com'): string
    {
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => $email]);

        return $this->login('acme', $email);
    }

    public function test_a_coupon_discounts_the_order_and_its_use_is_recorded(): void
    {
        $course = $this->course();
        $promotion = $this->promotion()->assertCreated()
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.coupons.0.code', 'VERANO')
            ->json('data.id');

        // El comprador prueba el cupón: no distingue mayúsculas
        $this->check(' verano ', $course)->assertOk()
            ->assertJsonPath('data.code', 'VERANO')
            ->assertJsonPath('data.subtotal_amount', '150.00')
            ->assertJsonPath('data.discount_amount', '30.00')
            ->assertJsonPath('data.total_amount', '120.00');

        // El total que vio debe ser el que calcula el servidor (RN-008)
        $this->buy($course, ['coupon_code' => 'VERANO', 'expected_total' => 150])
            ->assertStatus(409)->assertJsonPath('code', 'PRICE_CHANGED')->assertJsonPath('details.total', '120.00');

        $order = $this->buy($course, ['coupon_code' => 'verano', 'expected_total' => 120])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.subtotal_amount', '150.00')
            ->assertJsonPath('data.discount_amount', '30.00')
            ->assertJsonPath('data.total_amount', '120.00')
            ->assertJsonPath('data.coupon_code', 'VERANO')
            ->json('data');

        // El descuento queda fijado en la orden aunque la promoción cambie después
        $this->withToken($this->admin)->putJson($this->api("/promotions/{$promotion}"), [
            'name' => 'Campaña de verano', 'discount_type' => 'PERCENT', 'discount_value' => 50, 'once_per_customer' => true, 'applies_to' => 'ALL',
        ])->assertOk()->assertJsonPath('data.uses', 1)->assertJsonPath('data.coupons.0.uses', 1);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order['id']}/confirm-payment"), ['method' => 'YAPE'])
            ->assertOk()->assertJsonPath('data.payment.amount', '120.00');

        $this->withToken($this->admin)->getJson($this->api("/promotions/{$promotion}/redemptions"))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer', 'Ana Torres')
            ->assertJsonPath('data.0.discount_amount', '30.00')
            ->assertJsonPath('data.0.order.status', 'PAID');

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $created = AuditEvent::query()->where('action', 'orders.created')->latest('created_at')->first();
            $this->assertSame('VERANO', $created->after['coupon']);
            $this->assertSame(1, AuditEvent::query()->where('action', 'promotions.created')->count());
        });
    }

    public function test_a_fixed_coupon_never_goes_below_zero_and_a_free_total_enrolls_at_once(): void
    {
        $course = $this->course('Excel básico', 40);
        $this->promotion(['code' => 'REGALO', 'discount_type' => 'FIXED', 'discount_value' => 100])->assertCreated();

        $this->check('REGALO', $course)->assertOk()->assertJsonPath('data.discount_amount', '40.00')->assertJsonPath('data.total_amount', '0.00');

        $this->buy($course, ['coupon_code' => 'REGALO', 'expected_total' => 0])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.total_amount', '0.00')
            ->assertJsonPath('data.items.0.enrolled', true);

        $this->withToken($this->student)->getJson($this->api('/me/courses'))->assertJsonCount(1, 'data');
    }

    public function test_an_invalid_coupon_is_rejected_with_its_reason_and_creates_no_order(): void
    {
        $course = $this->course();
        $other = $this->course('Excel para finanzas', 80);

        $this->promotion(['code' => 'FUTURO', 'starts_at' => now()->addDay()->toIso8601String()])->assertCreated();
        $this->promotion(['code' => 'PASADO', 'starts_at' => now()->subDays(10)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()])->assertCreated();
        $this->promotion(['code' => 'SOLOEXCEL', 'applies_to' => 'SELECTED', 'items' => [['type' => 'COURSE', 'id' => $other]]])->assertCreated();
        $this->promotion(['code' => 'DESDE200', 'min_purchase' => 200])->assertCreated();
        $off = $this->promotion(['code' => 'APAGADA'])->assertCreated()->json('data');
        $this->withToken($this->admin)->putJson($this->api("/coupons/{$off['coupons'][0]['id']}"), ['status' => 'INACTIVE'])
            ->assertOk()->assertJsonPath('data.coupons.0.status', 'INACTIVE');

        foreach ([
            'NOEXISTE' => 'NOT_FOUND', 'FUTURO' => 'NOT_STARTED', 'PASADO' => 'EXPIRED',
            'SOLOEXCEL' => 'NOT_APPLICABLE', 'DESDE200' => 'MIN_PURCHASE', 'APAGADA' => 'INACTIVE',
        ] as $code => $reason) {
            $this->check($code, $course)->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID')->assertJsonPath('details.reason', $reason);
        }

        // El que sí aplica a su curso
        $this->check('SOLOEXCEL', $other)->assertOk()->assertJsonPath('data.discount_amount', '16.00');

        // TC-SAL-003: con un cupón inválido la orden no se crea; sin cupón, la compra sigue
        $this->buy($course, ['coupon_code' => 'PASADO'])->assertStatus(422)->assertJsonPath('details.reason', 'EXPIRED');
        $this->withToken($this->student)->getJson($this->api('/me/orders'))->assertJsonCount(0, 'data');
        $this->buy($course)->assertCreated()->assertJsonPath('data.discount_amount', '0.00')->assertJsonPath('data.coupon_code', null);

        // Un curso gratuito no necesita cupón
        $free = $this->course('Curso gratuito', 0);
        $this->promotion(['code' => 'TODO'])->assertCreated();
        $this->check('TODO', $free)->assertStatus(422)->assertJsonPath('details.reason', 'FREE');
    }

    public function test_limited_uses_are_freed_when_the_order_is_cancelled_or_expires(): void
    {
        $course = $this->course();
        $this->promotion(['code' => 'PRIMEROS', 'max_redemptions' => 1])->assertCreated();
        $second = $this->otherStudent();

        $order = $this->buy($course, ['coupon_code' => 'PRIMEROS'])->assertCreated()->json('data.id');

        // El uso cuenta desde que se crea la orden
        $this->check('PRIMEROS', $course, $second)->assertStatus(422)->assertJsonPath('details.reason', 'EXHAUSTED');
        $this->buy($course, ['coupon_code' => 'PRIMEROS'], $second)->assertStatus(422);
        $this->withToken($this->admin)->getJson($this->api('/promotions'))->assertJsonPath('data.0.state', 'EXHAUSTED');

        // Cancelada: el uso queda libre
        $this->withToken($this->student)->postJson($this->api("/me/orders/{$order}/cancel"))->assertOk();
        $this->check('PRIMEROS', $course, $second)->assertOk();
        $this->buy($course, ['coupon_code' => 'PRIMEROS'], $second)->assertCreated();
        $this->check('PRIMEROS', $course)->assertStatus(422)->assertJsonPath('details.reason', 'EXHAUSTED');

        // Vencida sin pago (72 h): también se libera
        $this->travel(73)->hours();
        $this->check('PRIMEROS', $course, $this->login('acme', 'ana@acme.com'))->assertOk();
    }

    public function test_a_promotion_is_used_once_per_customer_across_its_coupons(): void
    {
        $first = $this->course();
        $second = $this->course('Excel para finanzas', 80);
        $promotion = $this->promotion()->assertCreated()->json('data.id');

        // La misma promoción con otro código, para medir otro canal
        $this->withToken($this->admin)->postJson($this->api("/promotions/{$promotion}/coupons"), ['code' => 'verano-ig', 'label' => 'Instagram'])
            ->assertCreated()->assertJsonCount(2, 'data.coupons')->assertJsonPath('data.coupons.1.code', 'VERANO-IG');
        $this->withToken($this->admin)->postJson($this->api("/promotions/{$promotion}/coupons"), ['code' => 'Verano'])->assertStatus(422);
        $this->withToken($this->admin)->postJson($this->api("/promotions/{$promotion}/coupons"), ['code' => 'con espacio'])->assertStatus(422);

        $this->buy($first, ['coupon_code' => 'VERANO'])->assertCreated();

        $this->check('VERANO', $second)->assertStatus(422)->assertJsonPath('details.reason', 'ALREADY_USED');
        $this->check('VERANO-IG', $second)->assertStatus(422)->assertJsonPath('details.reason', 'ALREADY_USED');
        // Otra persona sí puede
        $this->check('VERANO-IG', $second, $this->otherStudent())->assertOk();

        // Si la promoción lo permite, se repite
        $this->promotion(['code' => 'SIEMPRE', 'name' => 'Siempre 10', 'discount_value' => 10, 'once_per_customer' => false])->assertCreated();
        $this->withToken($this->student)->postJson($this->api('/me/orders/'.$this->withToken($this->student)->getJson($this->api('/me/orders'))->json('data.0.id').'/cancel'))->assertOk();
        $this->buy($first, ['coupon_code' => 'SIEMPRE'])->assertCreated();
        $this->check('SIEMPRE', $second)->assertOk();
    }

    public function test_validity_days_are_counted_in_the_timezone_of_the_organization(): void
    {
        // La organización está en Lima (UTC-5): su 1 de diciembre termina a las 04:59:59 UTC del día 2
        $course = $this->course();

        $this->promotion(['code' => 'DICIEMBRE', 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-01'])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-12-01T05:00:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-12-02T04:59:59+00:00')
            ->assertJsonPath('data.starts_on', '2026-12-01')
            ->assertJsonPath('data.ends_on', '2026-12-01');

        // Cada salto supera el tiempo de inactividad: la alumna vuelve a entrar
        $checkAt = function (string $moment) use ($course): TestResponse {
            $this->travelTo($moment);

            return $this->check('DICIEMBRE', $course, $this->login('acme', 'ana@acme.com'));
        };

        // 30 de noviembre, 23:30 en Lima: todavía no empieza
        $checkAt('2026-12-01 04:30:00')->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        // 1 de diciembre, 22:00 en Lima (ya es día 2 en UTC): sigue vigente
        $checkAt('2026-12-02 03:00:00')->assertOk();
        // 2 de diciembre, 00:30 en Lima: venció
        $checkAt('2026-12-02 05:30:00')->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
    }

    public function test_promotions_are_validated(): void
    {
        $this->promotion(['discount_value' => 120])->assertStatus(422);
        $this->promotion(['discount_value' => 0])->assertStatus(422);
        $this->promotion(['applies_to' => 'SELECTED'])->assertStatus(422);
        $this->promotion(['applies_to' => 'SELECTED', 'items' => [['type' => 'COURSE', 'id' => '01a10f0f-f109-7092-8f66-7da6ea2b4f7c']]])->assertStatus(422);
        $this->promotion(['starts_at' => '2026-12-10', 'ends_at' => '2026-12-01'])->assertStatus(422);
        $this->promotion(['code' => 'ab'])->assertStatus(422);

        $this->withToken($this->admin)->getJson($this->api('/promotions'))->assertJsonCount(0, 'data');
    }

    public function test_promotions_respect_roles_and_organizations(): void
    {
        $course = $this->course();
        $promotion = $this->promotion()->assertCreated()->json('data.id');

        // El alumno usa cupones, pero no ve la gestión
        $this->withToken($this->student)->getJson($this->api('/promotions'))->assertStatus(403);

        // Ventas las ve para ofrecerlas, sin poder cambiarlas
        $this->createUser($this->tenant, RoleCatalog::SALES, ['email' => 'vera@acme.com']);
        $sales = $this->login('acme', 'vera@acme.com');
        $this->withToken($sales)->getJson($this->api('/promotions'))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($sales)->postJson($this->api('/promotions'), ['name' => 'X', 'code' => 'XXXX', 'discount_type' => 'PERCENT', 'discount_value' => 5, 'once_per_customer' => true, 'applies_to' => 'ALL'])
            ->assertStatus(403);

        $this->provisionTenant('globex', 'admin@globex.com');
        $globex = $this->login('globex', 'admin@globex.com');
        $url = fn (string $path) => $this->tenantUrl('globex', $path);

        $this->withToken($globex)->getJson($url('/promotions'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($globex)->getJson($url("/promotions/{$promotion}"))->assertStatus(404);
        // El mismo código puede existir en otra organización, y el de Acme no sirve allí
        $this->withToken($globex)->postJson($url('/promotions'), ['name' => 'Otra', 'code' => 'VERANO', 'discount_type' => 'FIXED', 'discount_value' => 5, 'once_per_customer' => true, 'applies_to' => 'ALL'])
            ->assertCreated();
        $this->withToken($globex)->postJson($url('/promotions'), [
            'name' => 'Ajena', 'code' => 'AJENA', 'discount_type' => 'PERCENT', 'discount_value' => 5, 'once_per_customer' => true,
            'applies_to' => 'SELECTED', 'items' => [['type' => 'COURSE', 'id' => $course]],
        ])->assertStatus(422);
    }
}
