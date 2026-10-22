<?php

namespace Tests\Feature\Schedules;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Horario de los docentes: lo arma el personal, lo confirma u observa el
 * docente, y los alumnos ven las clases en vivo confirmadas de sus cursos.
 */
class TeacherScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $course;

    private User $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $admin = fn () => $this->withToken($this->admin);

        // Curso publicado con Rosa como docente y Ana matriculada
        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es'])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa@acme.com']);
        $admin()->putJson($this->api("/courses/{$this->course}/teachers"), ['user_ids' => [$this->rosa->id]])->assertOk();

        $student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $admin()->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$student->id]])->assertCreated();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function week(string $link = 'https://meet.google.com/abc-defg-hij'): array
    {
        return [
            'valid_from' => '2026-11-02',
            'blocks' => [
                ['day' => 2, 'start' => '19:00', 'end' => '21:00', 'type' => 'LIVE_CLASS', 'course_id' => $this->course, 'meeting_url' => $link],
                ['day' => 1, 'start' => '09:00', 'end' => '13:00', 'type' => 'AVAILABLE'],
                ['day' => 1, 'start' => '15:00', 'end' => '17:30', 'type' => 'GRADING', 'note' => 'Tareas de Excel'],
            ],
        ];
    }

    public function test_the_admin_sends_a_schedule_and_the_teacher_confirms_it_for_the_students(): void
    {
        Mail::fake();

        $this->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), $this->week())
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            // Quedan ordenados por día y hora
            ->assertJsonPath('data.blocks.0.start', '09:00')
            ->assertJsonPath('data.blocks.2.course_title', 'Excel para finanzas')
            ->assertJsonPath('data.confirmed', null);

        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('rosa@acme.com') && $mail->title === 'Tienes un horario por confirmar');

        $this->withToken($this->admin)->getJson($this->api('/schedules'))
            ->assertOk()
            ->assertJsonPath('meta.counts.PENDING', 1)
            ->assertJsonPath('data.0.hours.AVAILABLE', 4)
            ->assertJsonPath('data.0.hours.GRADING', 2.5)
            ->assertJsonPath('data.0.hours.LIVE_CLASS', 2);

        // Mientras no lo confirma, los alumnos no ven la clase
        $student = $this->login('acme', 'ana@acme.com');
        $this->flushHeaders()->withToken($student)->getJson($this->api('/me/live-classes'))->assertOk()->assertJsonCount(0, 'data');

        $teacher = $this->login('acme', 'rosa@acme.com');
        $this->flushHeaders()->withToken($teacher)->getJson($this->api('/alerts/summary'))->assertJsonPath('data.schedule_to_confirm', 1);
        $this->withToken($teacher)->getJson($this->api('/me/schedule'))->assertOk()->assertJsonPath('data.status', 'PENDING');
        $this->withToken($teacher)->postJson($this->api('/me/schedule/confirm'))->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        // Ya respondido, no se confirma dos veces
        $this->withToken($teacher)->postJson($this->api('/me/schedule/confirm'))->assertStatus(409);

        $this->flushHeaders()->withToken($student)->getJson($this->api('/me/live-classes'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course_title', 'Excel para finanzas')
            ->assertJsonPath('data.0.teacher', 'Rosa Quispe')
            ->assertJsonPath('data.0.day', 2)
            ->assertJsonPath('data.0.meeting_url', 'https://meet.google.com/abc-defg-hij');
    }

    public function test_a_change_asks_again_and_students_keep_the_confirmed_version(): void
    {
        $this->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), $this->week())->assertOk();
        $teacher = $this->login('acme', 'rosa@acme.com');
        $this->flushHeaders()->withToken($teacher)->postJson($this->api('/me/schedule/confirm'))->assertOk();

        // Guardar lo mismo no vuelve a pedir confirmación
        $this->flushHeaders()->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), $this->week())
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        // Un cambio sí, y la docente lo observa
        $this->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), $this->week('https://zoom.us/j/123'))
            ->assertOk()->assertJsonPath('data.status', 'PENDING');

        Mail::fake();
        $this->flushHeaders()->withToken($teacher)->postJson($this->api('/me/schedule/observe'), ['observation' => 'Los martes no puedo, mejor los jueves.'])
            ->assertOk()->assertJsonPath('data.status', 'OBSERVED');
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('admin@acme.com') && $mail->title === 'Rosa Quispe pidió cambios en su horario');

        $this->flushHeaders()->withToken($this->admin)->getJson($this->api('/alerts/summary'))->assertJsonPath('data.schedules_observed', 1);
        $this->withToken($this->admin)->getJson($this->api("/schedules/{$this->rosa->id}"))
            ->assertOk()
            ->assertJsonPath('data.schedule.observation', 'Los martes no puedo, mejor los jueves.')
            ->assertJsonPath('data.schedule.confirmed.blocks.2.meeting_url', 'https://meet.google.com/abc-defg-hij');

        // El alumno sigue viendo el enlace confirmado
        $student = $this->login('acme', 'ana@acme.com');
        $this->flushHeaders()->withToken($student)->getJson($this->api('/me/live-classes'))
            ->assertJsonPath('data.0.meeting_url', 'https://meet.google.com/abc-defg-hij');
    }

    public function test_overlapping_blocks_and_foreign_courses_are_rejected(): void
    {
        $week = $this->week();
        $week['blocks'][] = ['day' => 1, 'start' => '12:00', 'end' => '14:00', 'type' => 'GRADING'];

        $this->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), $week)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dos bloques se cruzan el lunes: 09:00–13:00 y 12:00–14:00.');

        $other = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'Word básico', 'language' => 'es'])->json('data.id');
        $week = $this->week();
        $week['blocks'][0]['course_id'] = $other;
        $this->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), $week)
            ->assertStatus(422)->assertJsonValidationErrors(['blocks.0.course_id'], 'details');

        $this->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), ['valid_from' => '2026-11-02', 'blocks' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['blocks'], 'details');

        $week = $this->week();
        $week['blocks'][1]['end'] = '08:00';
        $this->withToken($this->admin)->putJson($this->api("/schedules/{$this->rosa->id}"), $week)
            ->assertStatus(422)->assertJsonValidationErrors(['blocks.1.end'], 'details');
    }

    public function test_only_teachers_have_schedules_and_teachers_cannot_edit_them(): void
    {
        $student = User::query()->where('email', 'ana@acme.com')->firstOrFail();
        $this->withToken($this->admin)->putJson($this->api("/schedules/{$student->id}"), $this->week())->assertNotFound();

        $teacher = $this->login('acme', 'rosa@acme.com');
        $this->flushHeaders()->withToken($teacher)->putJson($this->api("/schedules/{$this->rosa->id}"), $this->week())->assertForbidden();
        $this->withToken($teacher)->getJson($this->api('/schedules'))->assertForbidden();
        $this->withToken($teacher)->getJson($this->api('/me/schedule'))->assertOk()->assertJsonPath('data', null);
    }
}
