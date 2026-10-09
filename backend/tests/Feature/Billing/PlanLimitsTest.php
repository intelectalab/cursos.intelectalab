<?php

namespace Tests\Feature\Billing;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que el plan limita: el cupo de alumnos y, al vencer, los días de
 * gracia y el paso a solo lectura.
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $alfa;

    private string $admin;

    /** Recurso del último curso publicado */
    private string $resource;

    protected function setUp(): void
    {
        parent::setUp();

        // Prueba gratuita con cupo para dos alumnos
        config(['billing.trial_max_students' => 2]);

        $this->alfa = $this->provisionTenant('alfa', 'admin@alfa.com');
        $this->admin = $this->login('alfa', 'admin@alfa.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('alfa', $path);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publishedCourse(array $attributes = []): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), $attributes + ['title' => 'Excel', 'language' => 'es'])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $this->resource = $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Hola', 'body' => 'Hola.'])
            ->assertCreated()->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    private function student(string $email): User
    {
        return $this->createUser($this->alfa, RoleCatalog::STUDENT, ['email' => $email]);
    }

    private function platformToken(): string
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);

        return $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->assertOk()->json('data.token');
    }

    public function test_a_full_quota_stops_new_students_but_not_the_ones_already_enrolled(): void
    {
        $excel = $this->publishedCourse();
        $word = $this->publishedCourse(['title' => 'Word']);
        [$ana, $beto, $caro] = [$this->student('ana@alfa.com'), $this->student('beto@alfa.com'), $this->student('caro@alfa.com')];
        $enroll = fn (string $course, array $ids) => $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => $ids]);

        // Tres de golpe no caben en un cupo de dos: no se matricula a ninguno
        $enroll($excel, [$ana->id, $beto->id, $caro->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'STUDENT_LIMIT_REACHED')
            ->assertJsonPath('details.max_students', 2)
            ->assertJsonPath('details.requested', 3);
        $this->withToken($this->admin)->getJson($this->api('/subscription'))->assertJsonPath('data.students', 0);

        $enroll($excel, [$ana->id, $beto->id])->assertCreated();
        $enroll($excel, [$caro->id])->assertStatus(409)->assertJsonPath('code', 'STUDENT_LIMIT_REACHED');
        $this->withToken($this->admin)->postJson($this->api("/courses/{$excel}/participants"), ['role' => 'STUDENT', 'user_ids' => [$caro->id]])
            ->assertStatus(409)->assertJsonPath('code', 'STUDENT_LIMIT_REACHED');

        // Quien ya es alumno no ocupa otro lugar al entrar a un segundo curso
        $enroll($word, [$ana->id])->assertCreated();
        $this->withToken($this->admin)->getJson($this->api('/subscription'))
            ->assertJsonPath('data.students', 2)
            ->assertJsonPath('data.seats_left', 0);

        // Al liberar un lugar vuelve a haber cupo
        $betoEnrollment = $this->withToken($this->admin)->getJson($this->api("/courses/{$excel}/enrollments"))->collect('data')->firstWhere('student.id', $beto->id)['id'];
        $this->withToken($this->admin)->deleteJson($this->api("/courses/{$excel}/enrollments/{$betoEnrollment}"))->assertOk();
        $enroll($excel, [$caro->id])->assertCreated();
    }

    public function test_a_full_quota_stops_new_purchases_but_honours_orders_already_placed(): void
    {
        $paid = $this->publishedCourse(['price' => 100]);
        $free = $this->publishedCourse(['title' => 'Gratis', 'price' => 0]);
        [$ana, $beto, $caro, $dina] = [$this->student('ana@alfa.com'), $this->student('beto@alfa.com'), $this->student('caro@alfa.com'), $this->student('dina@alfa.com')];

        // Caro deja su orden mientras hay lugar
        $order = $this->withToken($this->login('alfa', $caro->email))->postJson($this->api('/orders'), ['course_id' => $paid])->assertCreated()->json('data.id');

        $this->withToken($this->admin)->postJson($this->api("/courses/{$paid}/enrollments"), ['student_ids' => [$ana->id, $beto->id]])->assertCreated();

        // Con el cupo lleno, quien no es alumno no puede comprar ni inscribirse gratis
        $this->withToken($this->login('alfa', $dina->email))->postJson($this->api('/orders'), ['course_id' => $free])
            ->assertStatus(409)->assertJsonPath('code', 'STUDENT_LIMIT_REACHED');
        // Quien ya es alumno sí puede comprar otro curso
        $this->withToken($this->login('alfa', $ana->email))->postJson($this->api('/orders'), ['course_id' => $free])->assertCreated();

        // La orden que Caro ya tenía se respeta al confirmarse el pago, aunque pase el tope
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER'])->assertOk();
        $this->withToken($this->login('alfa', $caro->email))->getJson($this->api("/me/courses/{$paid}"))->assertOk();
        $this->withToken($this->admin)->getJson($this->api('/subscription'))
            ->assertJsonPath('data.students', 3)
            ->assertJsonPath('data.seats_left', 0);
    }

    public function test_an_expired_trial_gets_grace_days_and_then_becomes_read_only_until_it_pays(): void
    {
        $platform = $this->platformToken();
        $plan = $this->withToken($platform)->postJson($this->centralUrl('/platform/plans'), [
            'name' => 'Hasta 50 alumnos', 'price' => 99, 'currency' => 'PEN', 'billing_months' => 1, 'max_students' => 50,
        ])->assertCreated()->json('data.id');

        $course = $this->publishedCourse();
        $ana = $this->student('ana@alfa.com');
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$ana->id]])->assertCreated();

        $this->withToken($this->admin)->getJson($this->api('/subscription'))
            ->assertJsonPath('data.access', 'FULL')
            ->assertJsonPath('data.grace_ends_at', null);

        // Vencida la prueba: siete días de gracia en los que todo sigue funcionando
        $this->travel(15)->days();
        $admin = $this->login('alfa', 'admin@alfa.com');
        $this->withToken($admin)->getJson($this->api('/subscription'))
            ->assertJsonPath('data.state', 'TRIAL_EXPIRED')
            ->assertJsonPath('data.access', 'GRACE')
            ->assertJsonPath('data.grace_days_left', 6);
        $this->withToken($admin)->postJson($this->api('/courses'), ['title' => 'En gracia', 'language' => 'es'])->assertCreated();

        // Pasada la gracia: solo lectura
        $this->travel(7)->days();
        $admin = $this->login('alfa', 'admin@alfa.com');
        $this->withToken($admin)->getJson($this->api('/subscription'))
            ->assertJsonPath('data.access', 'READ_ONLY')
            ->assertJsonPath('data.grace_days_left', 0);
        $this->withToken($admin)->getJson($this->api('/courses'))->assertOk();
        $this->withToken($admin)->postJson($this->api('/courses'), ['title' => 'Bloqueado', 'language' => 'es'])
            ->assertForbidden()->assertJsonPath('code', 'PLAN_READ_ONLY');
        $this->withToken($admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$this->student('beto@alfa.com')->id]])
            ->assertForbidden()->assertJsonPath('code', 'PLAN_READ_ONLY');

        // No entran cuentas nuevas, pero la alumna que ya estaba sigue estudiando
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->api('/auth/register'), [
            'first_name' => 'Nora', 'last_name' => 'Nueva', 'email' => 'nora@alfa.com', 'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123', 'accept_terms' => true,
        ])->assertForbidden()->assertJsonPath('code', 'PLAN_READ_ONLY');
        $this->withToken($this->login('alfa', $ana->email))->postJson($this->api("/me/courses/{$course}/resources/{$this->resource}/complete"))->assertOk();

        // La academia sí puede pedir su plan; confirmado el pago, vuelve a operar
        $order = $this->withToken($admin)->postJson($this->api('/subscription/orders'), ['plan_id' => $plan])->assertCreated()->json('data.id');
        $this->withToken($platform = $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->json('data.token'))
            ->postJson($this->centralUrl("/platform/plan-orders/{$order}/confirm"), ['method' => 'TRANSFER'])->assertOk();

        $this->withToken($admin)->getJson($this->api('/subscription'))
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.access', 'FULL');
        $this->withToken($admin)->postJson($this->api('/courses'), ['title' => 'De vuelta', 'language' => 'es'])->assertCreated();
    }

    public function test_an_organization_without_a_plan_has_no_limits(): void
    {
        $platform = $this->platformToken();
        $this->withToken($platform)->putJson($this->centralUrl("/platform/tenants/{$this->alfa->id}/subscription"), ['plan_id' => null])->assertOk();

        $course = $this->publishedCourse();
        $students = collect(['ana', 'beto', 'caro'])->map(fn (string $name) => $this->student("{$name}@alfa.com")->id)->all();

        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => $students])->assertCreated();

        $this->travel(60)->days();
        $admin = $this->login('alfa', 'admin@alfa.com');
        $this->withToken($admin)->getJson($this->api('/subscription'))->assertJsonPath('data.access', 'FULL');
        $this->withToken($admin)->postJson($this->api('/courses'), ['title' => 'Sin límites', 'language' => 'es'])->assertCreated();
    }
}
