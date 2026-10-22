<?php

namespace Tests\Feature\Assistant;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asistente, entrega 2: fichas de curso y de alumno, horarios de un día y lo
 * del propio alumno. Cada una respeta el alcance del rol.
 */
class AssistantContextTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $excel;

    private User $rosa;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.trial_days' => 365]);
        // Lunes 2 de marzo de 2026, 10:00 en Lima
        $this->travelTo('2026-03-02 15:00:00');
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $admin = fn () => $this->withToken($this->admin);

        $this->excel = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 100])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->excel}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->excel}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->excel}/publish"))->assertOk();

        $this->rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa@acme.com']);
        $admin()->putJson($this->api("/courses/{$this->excel}/teachers"), ['user_ids' => [$this->rosa->id]])->assertOk();

        $this->ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@acme.com']);
        $this->luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Luis', 'last_name' => 'Vega', 'email' => 'luis@acme.com']);

        // Ana compra y paga; Luis deja su orden pendiente
        $order = $this->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->api('/orders'), ['course_id' => $this->excel])->json('data.id');
        $this->flushHeaders()->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE'])->assertOk();
        $this->flushHeaders()->withToken($this->login('acme', 'luis@acme.com'))->postJson($this->api('/orders'), ['course_id' => $this->excel])->assertCreated();

        // Clase en vivo de Excel los jueves a las 19:00, confirmada por Rosa
        $this->flushHeaders()->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), [
            'valid_from' => '2026-03-02',
            'blocks' => [
                ['day' => 4, 'start' => '19:00', 'end' => '21:00', 'type' => 'LIVE_CLASS', 'course_id' => $this->excel],
                ['day' => 1, 'start' => '09:00', 'end' => '12:00', 'type' => 'AVAILABLE'],
            ],
        ])->assertOk();
        $this->flushHeaders()->withToken($this->login('acme', 'rosa@acme.com'))->postJson($this->api('/me/schedule/confirm'))->assertOk();
        $this->flushHeaders();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    public function test_the_course_card_shows_students_sales_and_the_next_live_class(): void
    {
        $this->withToken($this->admin)->getJson($this->api("/assistant/courses/{$this->excel}/context"))
            ->assertOk()
            ->assertJsonPath('data.title', 'Excel para finanzas')
            ->assertJsonPath('data.teachers.0', 'Rosa Quispe')
            ->assertJsonPath('data.students.with_access', 1)
            ->assertJsonPath('data.sales.units', 1)
            ->assertJsonPath('data.sales.revenue', '100.00')
            ->assertJsonPath('data.pending_submissions', 0)
            // Lunes 2: la próxima clase es el jueves 5
            ->assertJsonPath('data.next_class.date', '2026-03-05')
            ->assertJsonPath('data.next_class.start', '19:00');

        // La docente ve su curso, sin las ventas; otro docente no lo ve
        $this->withToken($this->login('acme', 'rosa@acme.com'))->getJson($this->api("/assistant/courses/{$this->excel}/context"))
            ->assertOk()
            ->assertJsonPath('data.sales', null)
            ->assertJsonPath('data.students.with_access', 1);
        $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'saul@acme.com']);
        $this->flushHeaders()->withToken($this->login('acme', 'saul@acme.com'))->getJson($this->api("/assistant/courses/{$this->excel}/context"))->assertNotFound();
    }

    public function test_the_student_card_shows_what_the_person_owes(): void
    {
        $this->withToken($this->admin)->getJson($this->api('/assistant/students?search=vega'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Luis Vega');

        $this->withToken($this->admin)->getJson($this->api("/assistant/students/{$this->luis->id}/context"))
            ->assertOk()
            ->assertJsonPath('data.student.name', 'Luis Vega')
            ->assertJsonPath('data.debt.0.total', '100.00')
            ->assertJsonPath('data.debt.0.orders', 1);

        $this->withToken($this->admin)->getJson($this->api("/assistant/students/{$this->ana->id}/context"))
            ->assertJsonPath('data.debt', [])
            ->assertJsonPath('data.enrollments.0.course_title', 'Excel para finanzas');

        // La ficha de alguien que no es alumno no existe aquí, y el docente no ve fichas
        $this->withToken($this->admin)->getJson($this->api("/assistant/students/{$this->rosa->id}/context"))->assertNotFound();
        $this->flushHeaders()->withToken($this->login('acme', 'rosa@acme.com'))->getJson($this->api('/assistant/students?search=vega'))->assertForbidden();
    }

    public function test_the_student_sees_only_their_own_progress_debt_and_classes(): void
    {
        $this->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api('/assistant/me'))
            ->assertOk()
            ->assertJsonPath('data.enrollments.0.course_title', 'Excel para finanzas')
            ->assertJsonPath('data.debt', [])
            ->assertJsonPath('data.live_classes.0.day', 4)
            ->assertJsonPath('data.grade_scale', 20);

        $this->flushHeaders()->withToken($this->login('acme', 'luis@acme.com'))->getJson($this->api('/assistant/me'))
            ->assertJsonPath('data.debt.0.total', '100.00')
            ->assertJsonPath('data.enrollments', []);

        // El alumno no entra a fichas ajenas ni a los horarios del personal
        $this->getJson($this->api("/assistant/students/{$this->ana->id}/context"))->assertForbidden();
        $this->getJson($this->api('/assistant/schedule?day=1'))->assertForbidden();
    }

    public function test_who_has_what_on_a_given_day(): void
    {
        $this->withToken($this->admin)->getJson($this->api('/assistant/schedule?day=1'))
            ->assertOk()
            ->assertJsonPath('data.0.teacher', 'Rosa Quispe')
            ->assertJsonPath('data.0.blocks.0.type', 'AVAILABLE');

        $this->withToken($this->admin)->getJson($this->api('/assistant/schedule?day=4'))
            ->assertJsonPath('data.0.blocks.0.course_title', 'Excel para finanzas');

        $this->withToken($this->admin)->getJson($this->api('/assistant/schedule?day=6'))->assertJsonCount(0, 'data');
    }
}
