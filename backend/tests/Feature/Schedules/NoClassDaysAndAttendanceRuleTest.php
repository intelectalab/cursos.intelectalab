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
 * Días sin clase (feriados y permisos del docente), recordatorio el día de
 * la clase y la asistencia mínima como requisito del certificado.
 */
class NoClassDaysAndAttendanceRuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $course;

    private string $resource;

    private User $rosa;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        // Jueves 5 de noviembre de 2026, 13:00 en Lima
        $this->travelTo('2026-11-05 18:00:00');
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $admin = fn () => $this->withToken($this->admin);

        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'issues_certificate' => true, 'min_attendance_percent' => 75])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $this->resource = $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa@acme.com']);
        $admin()->putJson($this->api("/courses/{$this->course}/teachers"), ['user_ids' => [$this->rosa->id]])->assertOk();
        $this->ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'email' => 'ana@acme.com']);
        $admin()->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$this->ana->id]])->assertCreated();

        // Clase en vivo los jueves a las 19:00
        $admin()->putJson($this->api("/schedules/{$this->rosa->id}"), [
            'valid_from' => '2026-11-02',
            'blocks' => [['day' => 4, 'start' => '19:00', 'end' => '21:00', 'type' => 'LIVE_CLASS', 'course_id' => $this->course, 'meeting_url' => 'https://meet.google.com/abc']],
        ])->assertOk();
        $this->flushHeaders()->withToken($this->login('acme', 'rosa@acme.com'))->postJson($this->api('/me/schedule/confirm'))->assertOk();
        $this->flushHeaders();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    public function test_a_day_off_or_a_holiday_has_no_class_nor_reminder(): void
    {
        Mail::fake();

        // Un jueves normal: hay clase y sale el recordatorio
        $this->artisan('live-classes:remind')->assertSuccessful();
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('ana@acme.com') && $mail->title === 'Hoy tienes clase en vivo de Excel para finanzas');

        // Rosa no da clase el 12; el 19 es feriado de la academia
        $this->withToken($this->admin)->postJson($this->api("/schedules/{$this->rosa->id}/exceptions"), ['date' => '2026-11-12', 'reason' => 'Viaje'])
            ->assertCreated()->assertJsonPath('data.0.reason', 'Viaje');
        $this->withToken($this->admin)->postJson($this->api('/settings/holidays'), ['date' => '2026-11-19', 'name' => 'Feriado de prueba'])->assertCreated();

        $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/attendance?date=2026-11-12"))->assertJsonCount(0, 'data.sessions');
        $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/attendance?date=2026-11-19"))->assertJsonCount(0, 'data.sessions');

        // El alumno ve que esos días no hay clase
        $this->flushHeaders()->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api('/me/live-classes'))
            ->assertJsonPath('data.0.cancelled.0.date', '2026-11-12')
            ->assertJsonPath('data.0.cancelled.1.date', '2026-11-19');

        // El 12 no sale recordatorio
        Mail::fake();
        $this->travelTo('2026-11-12 12:00:00');
        $this->artisan('live-classes:remind')->assertSuccessful();
        Mail::assertNotSent(TenantNoticeMail::class);
    }

    public function test_the_certificate_waits_for_the_minimum_attendance(): void
    {
        $teacher = fn () => $this->flushHeaders()->withToken($this->login('acme', 'rosa@acme.com'));

        // Ana completa el curso, pero faltó a la única clase: no hay certificado
        $teacher()->putJson($this->api("/courses/{$this->course}/attendance"), ['date' => '2026-11-05', 'start' => '19:00', 'records' => [['student_id' => $this->ana->id, 'status' => 'ABSENT']]])->assertOk();
        $this->flushHeaders()->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->api("/me/courses/{$this->course}/resources/{$this->resource}/complete"))->assertOk();
        $this->assertDatabaseMissing('certificates', ['student_id' => $this->ana->id]);

        $enrollment = collect($this->flushHeaders()->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/enrollments"))->json('data'))->first()['id'];
        $this->withToken($this->admin)->postJson($this->api('/certificates/issue'), ['enrollment_id' => $enrollment])
            ->assertStatus(409)
            ->assertJsonPath('message', 'El alumno no alcanza la asistencia mínima a las clases en vivo (75 %).');

        // Se corrige la asistencia (llegó tarde): ahora cumple y el certificado sale solo
        $teacher()->putJson($this->api("/courses/{$this->course}/attendance"), ['date' => '2026-11-05', 'start' => '19:00', 'records' => [['student_id' => $this->ana->id, 'status' => 'LATE']]])->assertOk();
        $this->assertDatabaseHas('certificates', ['student_id' => $this->ana->id, 'status' => 'ISSUED']);
    }
}
