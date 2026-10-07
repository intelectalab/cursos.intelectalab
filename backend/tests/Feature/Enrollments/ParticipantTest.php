<?php

namespace Tests\Feature\Enrollments;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Participantes de un curso: alumnos y docentes se agregan desde el mismo
 * lugar, y las acciones sobre varias filas a la vez.
 */
class ParticipantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function publishedCourse(string $title = 'Excel para finanzas'): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'language' => 'es'])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    /**
     * @param  list<string>  $userIds
     */
    private function add(string $course, array $userIds, string $role, ?string $token = null)
    {
        return $this->withToken($token ?? $this->admin)->postJson($this->api("/courses/{$course}/participants"), ['user_ids' => $userIds, 'role' => $role]);
    }

    public function test_the_same_person_teaches_one_course_and_studies_another(): void
    {
        $first = $this->publishedCourse();
        $second = $this->publishedCourse('Power BI');
        // Entra a la organización solo como alumna
        $ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);

        $this->add($first, [$ana->id], 'TEACHER')
            ->assertCreated()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.roles_granted', 1);
        $this->add($second, [$ana->id], 'STUDENT')
            ->assertCreated()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.roles_granted', 0);

        // Como docente ve y edita el contenido del primero; del segundo solo es alumna
        $token = $this->login('acme', 'ana@acme.com');
        $this->withToken($token)->getJson($this->api("/courses/{$first}"))->assertOk();
        $this->withToken($token)->getJson($this->api("/courses/{$second}"))->assertNotFound();
        $this->withToken($token)->getJson($this->api("/me/courses/{$second}"))->assertOk();

        $this->withToken($this->admin)->getJson($this->api("/courses/{$first}"))->assertJsonPath('data.teachers.0.email', 'ana@acme.com');
        $this->assertTrue(AuditEvent::query()->where('action', 'users.roles_updated')->where('entity_id', $ana->id)->exists());
    }

    public function test_nobody_is_student_and_teacher_of_the_same_course(): void
    {
        $course = $this->publishedCourse();
        $ana = $this->createUser($this->tenant, RoleCatalog::STUDENT);
        $luis = $this->createUser($this->tenant, RoleCatalog::TEACHER);

        $this->add($course, [$ana->id], 'STUDENT')->assertCreated();
        $this->add($course, [$luis->id], 'TEACHER')->assertCreated();

        $this->add($course, [$ana->id], 'TEACHER')->assertStatus(422)->assertJsonStructure(['details' => ['user_ids']]);
        $this->add($course, [$luis->id], 'STUDENT')->assertStatus(422)->assertJsonStructure(['details' => ['user_ids']]);

        // Quien ya participa deja de aparecer entre los candidatos
        $candidates = $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/participant-candidates"))->assertOk()->json('data.*.id');
        $this->assertNotContains($ana->id, $candidates);
        $this->assertNotContains($luis->id, $candidates);

        // Al quitarlo como docente puede entrar como alumno; conserva su rol de profesor
        $this->withToken($this->admin)->deleteJson($this->api("/courses/{$course}/teachers/{$luis->id}"))->assertOk();
        $this->withToken($this->admin)->deleteJson($this->api("/courses/{$course}/teachers/{$luis->id}"))->assertNotFound();
        $this->add($course, [$luis->id], 'STUDENT')->assertCreated()->assertJsonPath('data.roles_granted', 1);
    }

    public function test_each_role_in_a_course_needs_its_own_permission_and_stays_in_its_organization(): void
    {
        $course = $this->publishedCourse();
        $ana = $this->createUser($this->tenant, RoleCatalog::STUDENT);
        $support = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::SUPPORT)->email);
        $student = $this->login('acme', $ana->email);

        $this->add($course, [$ana->id], 'STUDENT', $student)->assertForbidden();
        $this->add($course, [$ana->id], 'TEACHER', $support)->assertForbidden();
        $this->add($course, [$ana->id], 'DIRECTOR')->assertStatus(422);

        $beta = $this->provisionTenant('beta', 'admin@beta.com');
        $outsider = $this->createUser($beta, RoleCatalog::STUDENT);
        $this->add($course, [$outsider->id], 'STUDENT')->assertStatus(422);
        $this->withToken($this->login('beta', 'admin@beta.com'))
            ->postJson($this->tenantUrl('beta', "/courses/{$course}/participants"), ['user_ids' => [$outsider->id], 'role' => 'STUDENT'])
            ->assertNotFound();
    }

    public function test_several_enrollments_are_cancelled_or_rescheduled_at_once(): void
    {
        $course = $this->publishedCourse();
        $students = collect(range(1, 3))->map(fn () => $this->createUser($this->tenant, RoleCatalog::STUDENT)->id)->all();
        $this->add($course, $students, 'STUDENT')->assertCreated()->assertJsonPath('data.added', 3);

        $ids = $this->withToken($this->admin)->getJson($this->api('/enrollments'))
            ->assertOk()
            ->assertJsonPath('meta.counts.ALL', 3)
            ->assertJsonPath('meta.counts.ACTIVE', 3)
            ->json('data.*.id');

        $this->withToken($this->admin)->postJson($this->api('/enrollments/bulk-validity'), ['enrollment_ids' => $ids, 'ends_at' => now()->addDays(3)->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.updated', 3);
        $this->withToken($this->admin)->getJson($this->api('/enrollments?state=EXPIRING'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.counts.EXPIRING', 3);

        $this->withToken($this->admin)->postJson($this->api('/enrollments/bulk-cancel'), ['enrollment_ids' => array_slice($ids, 0, 2)])
            ->assertOk()
            ->assertJsonPath('data.updated', 2);
        // Repetirlo no falla: las ya canceladas se omiten
        $this->withToken($this->admin)->postJson($this->api('/enrollments/bulk-cancel'), ['enrollment_ids' => $ids])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 2);

        $this->withToken($this->admin)->getJson($this->api('/enrollments'))
            ->assertJsonPath('meta.counts.CANCELLED', 3)
            ->assertJsonPath('meta.counts.ACTIVE', 0);

        $student = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::STUDENT)->email);
        $this->withToken($student)->postJson($this->api('/enrollments/bulk-cancel'), ['enrollment_ids' => $ids])->assertForbidden();
    }

    public function test_several_accounts_are_deactivated_at_once_except_the_ones_that_cannot_be(): void
    {
        $users = collect(range(1, 2))->map(fn () => $this->createUser($this->tenant, RoleCatalog::STUDENT))->all();
        $adminId = $this->withToken($this->admin)->getJson($this->api('/auth/me'))->json('data.user.id');
        $open = $this->login('acme', $users[0]->email);

        $this->withToken($this->admin)->postJson($this->api('/users/bulk-status'), [
            'user_ids' => [$users[0]->id, $users[1]->id, $adminId],
            'status' => 'INACTIVE',
        ])
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.skipped.0.email', 'admin@acme.com');

        // Las cuentas desactivadas pierden su sesión; la del administrador sigue
        $this->withToken($open)->getJson($this->api('/auth/me'))->assertUnauthorized();
        $this->withToken($this->admin)->getJson($this->api('/users'))
            ->assertOk()
            ->assertJsonPath('meta.counts.INACTIVE', 2)
            ->assertJsonPath('meta.counts.ACTIVE', 1);

        $this->withToken($this->admin)->postJson($this->api('/users/bulk-status'), ['user_ids' => [$users[0]->id], 'status' => 'ACTIVE'])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);
    }
}
