<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtros de los listados del personal: por curso, grupo, rol, docente,
 * medio de pago, quién vendió, fechas y «por vencer».
 */
class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $token;

    private string $excel;

    private string $power;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->token = $this->login('acme', 'admin@acme.com');

        $this->excel = $this->course('Excel para finanzas');
        $this->power = $this->course('Power BI desde cero');

        $this->ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'email' => 'ana@acme.com']);
        $this->luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Luis', 'email' => 'luis@acme.com']);
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function course(string $title): string
    {
        $admin = fn () => $this->withToken($this->token);
        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'language' => 'es', 'price' => 100])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Hola', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    /**
     * @return list<string>
     */
    private function emails(string $path): array
    {
        return collect($this->withToken($this->token)->getJson($this->api($path))->assertOk()->json('data'))
            ->map(fn (array $row) => $row['email'] ?? $row['customer']['email'] ?? $row['student']['email'] ?? null)
            ->filter()->sort()->values()->all();
    }

    public function test_students_by_course_group_debt_and_registration_date(): void
    {
        $admin = $this->withToken($this->token);
        $admin->postJson($this->api("/courses/{$this->excel}/enrollments"), ['student_ids' => [$this->ana->id]])->assertCreated();
        $group = $admin->postJson($this->api('/groups'), ['name' => 'Turno noche'])->assertCreated()->json('data.id');
        $admin->postJson($this->api("/groups/{$group}/members"), ['user_ids' => [$this->luis->id]])->assertSuccessful();

        $this->assertSame(['ana@acme.com'], $this->emails("/students?course_id={$this->excel}"));
        $this->assertSame(['luis@acme.com'], $this->emails("/students?group_id={$group}"));
        $this->assertSame([], $this->emails('/students?debt=1'));
        $this->assertSame(['ana@acme.com', 'luis@acme.com'], $this->emails('/students?from='.now('America/Lima')->toDateString()));
        $this->assertSame([], $this->emails('/students?to='.now()->subYear()->toDateString()));
        $this->withToken($this->token)->get($this->api("/students/export?course_id={$this->excel}"))->assertOk();
        $this->withToken($this->token)->getJson($this->api('/students?from=2026-02-10&to=2026-01-01'))->assertStatus(422);

        // Matrículas por grupo y por fecha
        $this->assertSame(['ana@acme.com'], $this->emails('/enrollments?from='.now('America/Lima')->toDateString()));
        $this->assertSame([], $this->emails("/enrollments?group_id={$group}"));
        $this->withToken($this->token)->getJson($this->api('/enrollments?expires_from=2026-01-01&expires_to=2030-12-31'))->assertOk();
    }

    public function test_orders_by_course_method_seller_and_date(): void
    {
        $admin = $this->withToken($this->token);
        $admin->postJson($this->api('/orders/assisted'), ['student_id' => $this->ana->id, 'course_id' => $this->excel, 'mode' => 'PAID', 'payment' => ['method' => 'CASH']])->assertCreated();
        $this->flushHeaders();
        $this->withToken($this->login('acme', 'luis@acme.com'))->postJson($this->api('/orders'), ['course_id' => $this->power])->assertCreated();
        $this->flushHeaders();

        $admin = $this->login('acme', 'admin@acme.com');
        $this->token = $admin;
        $sellerId = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => User::query()->where('email', 'admin@acme.com')->value('id'));

        $this->assertSame(['ana@acme.com'], $this->emails("/orders?course_id={$this->excel}"));
        $this->assertSame(['ana@acme.com'], $this->emails('/orders?method=CASH'));
        $this->assertSame(['ana@acme.com'], $this->emails("/orders?seller_id={$sellerId}"));
        $this->assertSame(['ana@acme.com', 'luis@acme.com'], $this->emails('/orders?from='.now('America/Lima')->toDateString()));
        $this->withToken($admin)->get($this->api('/orders/export?method=CASH'))->assertOk();

        // Las opciones de los filtros traen a quien vendió
        $this->withToken($admin)->getJson($this->api('/filter-options'))->assertOk()
            ->assertJsonPath('data.sellers.0.id', $sellerId)
            ->assertJsonCount(2, 'data.courses');
    }

    public function test_users_by_role_courses_by_teacher_teachers_by_course(): void
    {
        $rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Rosa', 'email' => 'rosa@acme.com']);
        $this->withToken($this->token)->putJson($this->api("/courses/{$this->power}/teachers"), ['user_ids' => [$rosa->id]])->assertOk();
        $teacherRole = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Role::query()->where('code', RoleCatalog::TEACHER)->value('id'));

        $this->assertSame(['rosa@acme.com'], $this->emails("/users?role_id={$teacherRole}"));
        $this->assertSame(['rosa@acme.com'], $this->emails("/teachers?course_id={$this->power}"));
        $this->assertSame([], $this->emails("/teachers?course_id={$this->excel}"));

        $titles = collect($this->withToken($this->token)->getJson($this->api("/courses?teacher_id={$rosa->id}"))->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['Power BI desde cero'], $titles);
    }

    public function test_certificates_tickets_and_claims_by_date_and_claims_due_soon(): void
    {
        $today = now('America/Lima')->toDateString();
        $this->withToken($this->token)->getJson($this->api("/certificates?from={$today}"))->assertOk();
        $this->withToken($this->token)->getJson($this->api("/tickets?from={$today}&to={$today}"))->assertOk();

        $this->flushHeaders()->postJson($this->api('/claims'), [
            'type' => 'RECLAMO', 'first_name' => 'Eva', 'last_name' => 'Ríos', 'doc_type' => 'DNI', 'doc_number' => '45678912',
            'address' => 'Av. Lima 123', 'email' => 'eva@correo.com', 'good_type' => 'SERVICE', 'good_description' => 'Curso',
            'detail' => 'No pude entrar al curso que pagué.', 'request' => 'Que me den acceso.', 'accepted' => true,
        ])->assertCreated();

        $this->withToken($this->token)->getJson($this->api("/claims?from={$today}"))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->token)->getJson($this->api('/claims?to='.now()->subYear()->toDateString()))->assertOk()->assertJsonCount(0, 'data');
        // 15 días hábiles de plazo: hoy no está por vencer
        $this->withToken($this->token)->getJson($this->api('/claims?status=DUE_SOON'))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.states.DUE_SOON', 0);
    }
}
