<?php

namespace Tests\Feature\Schedules;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asistencia a las clases en vivo: el docente la toma en las clases de su
 * horario confirmado y el alumno ve su porcentaje.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $course;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        // Jueves 5 de noviembre de 2026, 13:00 en Lima
        $this->travelTo('2026-11-05 18:00:00');

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $admin = fn () => $this->withToken($this->admin);

        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es'])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa@acme.com']);
        $admin()->putJson($this->api("/courses/{$this->course}/teachers"), ['user_ids' => [$rosa->id]])->assertOk();

        $this->ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@acme.com']);
        $this->luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Luis', 'last_name' => 'Vega', 'email' => 'luis@acme.com']);
        $admin()->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$this->ana->id, $this->luis->id]])->assertCreated();

        // Clase en vivo los jueves a las 19:00, confirmada por Rosa
        $admin()->putJson($this->api("/schedules/{$rosa->id}"), [
            'valid_from' => '2026-11-02',
            'blocks' => [['day' => 4, 'start' => '19:00', 'end' => '21:00', 'type' => 'LIVE_CLASS', 'course_id' => $this->course]],
        ])->assertOk();
        $this->flushHeaders()->withToken($this->login('acme', 'rosa@acme.com'))->postJson($this->api('/me/schedule/confirm'))->assertOk();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function teacher(): string
    {
        $this->flushHeaders();

        return $this->login('acme', 'rosa@acme.com');
    }

    public function test_the_teacher_takes_attendance_of_a_scheduled_class(): void
    {
        $token = $this->teacher();

        $this->withToken($token)->getJson($this->api("/courses/{$this->course}/attendance"))
            ->assertOk()
            ->assertJsonPath('data.date', '2026-11-05')
            ->assertJsonPath('data.sessions.0.start', '19:00')
            ->assertJsonPath('data.sessions.0.teacher', 'Rosa Quispe')
            ->assertJsonCount(2, 'data.students');

        $this->withToken($token)->putJson($this->api("/courses/{$this->course}/attendance"), [
            'date' => '2026-11-05', 'start' => '19:00',
            'records' => [['student_id' => $this->ana->id, 'status' => 'PRESENT'], ['student_id' => $this->luis->id, 'status' => 'ABSENT']],
        ])->assertOk()->assertJsonPath('data.saved', 2);

        $this->withToken($token)->getJson($this->api("/courses/{$this->course}/attendance?date=2026-11-05"))
            ->assertJsonPath('data.students.0.statuses.19:00', 'PRESENT');

        // Un día que no llegó, una hora que no está en el horario o un día sin clase: no
        $this->withToken($token)->putJson($this->api("/courses/{$this->course}/attendance"), ['date' => '2026-11-12', 'start' => '19:00', 'records' => [['student_id' => $this->ana->id, 'status' => 'PRESENT']]])
            ->assertStatus(422);
        $this->withToken($token)->putJson($this->api("/courses/{$this->course}/attendance"), ['date' => '2026-11-05', 'start' => '08:00', 'records' => [['student_id' => $this->ana->id, 'status' => 'PRESENT']]])
            ->assertStatus(422);
        $this->withToken($token)->getJson($this->api("/courses/{$this->course}/attendance?date=2026-11-04"))->assertJsonCount(0, 'data.sessions');

        // Ana ve su asistencia
        $this->flushHeaders()->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api("/me/courses/{$this->course}/attendance"))
            ->assertOk()
            ->assertJsonPath('data.percent', 100)
            ->assertJsonPath('data.records.0.status', 'PRESENT');
    }

    public function test_the_summary_counts_late_as_attended_and_leaves_out_excused_absences(): void
    {
        // Entre clase y clase pasa una semana: la sesión vence y se vuelve a entrar
        $take = fn (string $date, string $ana, string $luis) => $this->withToken($this->teacher())->putJson($this->api("/courses/{$this->course}/attendance"), [
            'date' => $date, 'start' => '19:00',
            'records' => [['student_id' => $this->ana->id, 'status' => $ana], ['student_id' => $this->luis->id, 'status' => $luis]],
        ])->assertOk();

        $take('2026-11-05', 'PRESENT', 'EXCUSED');
        $this->travelTo('2026-11-13 18:00:00');
        $take('2026-11-12', 'LATE', 'ABSENT');
        $this->travelTo('2026-11-20 18:00:00');
        $take('2026-11-19', 'ABSENT', 'PRESENT');

        $this->withToken($this->teacher())->getJson($this->api("/courses/{$this->course}/attendance/summary"))
            ->assertOk()
            ->assertJsonPath('data.sessions_taken', 3)
            ->assertJsonPath('data.students.0.attended', 2)
            ->assertJsonPath('data.students.0.percent', 67)
            ->assertJsonPath('data.students.1.excused', 1)
            ->assertJsonPath('data.students.1.percent', 50);
    }

    public function test_only_people_who_follow_the_course_take_attendance(): void
    {
        $other = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'saul@acme.com']);
        $this->flushHeaders()->withToken($this->login('acme', $other->email))->getJson($this->api("/courses/{$this->course}/attendance"))->assertNotFound();

        $student = $this->login('acme', 'ana@acme.com');
        $this->flushHeaders()->withToken($student)->getJson($this->api("/courses/{$this->course}/attendance"))->assertForbidden();

        // Un curso en el que no está matriculado
        $foreign = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'Word básico', 'language' => 'es'])->json('data.id');
        $this->flushHeaders()->withToken($student)->getJson($this->api("/me/courses/{$foreign}/attendance"))->assertNotFound();
    }
}
