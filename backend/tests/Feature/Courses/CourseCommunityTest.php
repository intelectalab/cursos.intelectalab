<?php

namespace Tests\Feature\Courses;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Anuncios del docente a sus alumnos y reseñas de los alumnos, con la nota
 * promedio en el catálogo y en la ficha del docente.
 */
class CourseCommunityTest extends TestCase
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

        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 100])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa@acme.com']);
        $admin()->putJson($this->api("/courses/{$this->course}/teachers"), ['user_ids' => [$this->rosa->id]])->assertOk();

        foreach (['ana' => 'Torres', 'luis' => 'Vega'] as $name => $last) {
            $student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => ucfirst($name), 'last_name' => $last, 'email' => "{$name}@acme.com"]);
            $admin()->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$student->id]])->assertCreated();
        }
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function as(string $email): string
    {
        $this->flushHeaders();

        return $this->login('acme', $email);
    }

    public function test_the_teacher_announces_to_the_students_of_the_course(): void
    {
        Mail::fake();
        $rosa = $this->as('rosa@acme.com');

        $id = $this->withToken($rosa)->postJson($this->api("/courses/{$this->course}/announcements"), [
            'title' => 'Clase en vivo el viernes', 'body' => 'Esta semana la clase pasa al viernes a las 19:00.', 'notify' => true,
        ])->assertCreated()->assertJsonPath('data.emailed', true)->json('data.id');

        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('ana@acme.com') && $mail->title === 'Clase en vivo el viernes · Excel para finanzas');
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('luis@acme.com'));

        $this->withToken($rosa)->putJson($this->api("/courses/{$this->course}/announcements/{$id}"), ['title' => 'Clase en vivo el viernes 7', 'body' => 'A las 19:00.'])->assertOk();

        $this->withToken($this->as('ana@acme.com'))->getJson($this->api("/me/courses/{$this->course}/announcements"))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Clase en vivo el viernes 7')
            ->assertJsonPath('data.0.author', 'Rosa Quispe');

        // Otro docente no publica en un curso que no dicta, y el alumno no entra a la gestión
        $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'saul@acme.com']);
        $this->withToken($this->as('saul@acme.com'))->postJson($this->api("/courses/{$this->course}/announcements"), ['title' => 'x', 'body' => 'y'])->assertNotFound();
        $this->withToken($this->as('ana@acme.com'))->getJson($this->api("/courses/{$this->course}/announcements"))->assertForbidden();

        // El administrador puede borrar el de otro
        $this->withToken($this->as('admin@acme.com'))->deleteJson($this->api("/courses/{$this->course}/announcements/{$id}"))->assertOk();
    }

    public function test_students_review_the_course_and_the_academy_moderates(): void
    {
        $ana = $this->as('ana@acme.com');
        $this->withToken($ana)->putJson($this->api("/me/courses/{$this->course}/review"), ['rating' => 5, 'comment' => 'Muy práctico.'])->assertOk();
        $this->withToken($ana)->putJson($this->api("/me/courses/{$this->course}/review"), ['rating' => 4, 'comment' => 'Muy práctico.'])->assertOk()->assertJsonPath('data.rating', 4);
        $this->withToken($this->as('luis@acme.com'))->putJson($this->api("/me/courses/{$this->course}/review"), ['rating' => 2, 'comment' => 'Spam: compra en mi web'])->assertOk();

        // En el catálogo: promedio de las visibles y nombre con la inicial del apellido
        $this->flushHeaders()->getJson($this->api("/catalog/courses/{$this->course}"))
            ->assertOk()
            ->assertJsonPath('data.rating.average', 3)
            ->assertJsonPath('data.rating.count', 2)
            ->assertJsonFragment(['author' => 'Ana T.']);

        // La academia oculta la de Luis
        $admin = $this->as('admin@acme.com');
        $review = collect($this->withToken($admin)->getJson($this->api("/courses/{$this->course}/reviews"))->json('data'))->firstWhere('rating', 2)['id'];
        $this->withToken($admin)->postJson($this->api("/courses/{$this->course}/reviews/{$review}/hide"), ['reason' => 'Publicidad'])->assertOk()->assertJsonPath('data.status', 'HIDDEN');

        $this->flushHeaders()->getJson($this->api('/catalog/courses'))->assertJsonPath('data.0.rating.average', 4)->assertJsonPath('data.0.rating.count', 1);
        $this->withToken($this->as('admin@acme.com'))->getJson($this->api("/teachers/{$this->rosa->id}"))->assertJsonPath('data.rating.average', 4);

        // El docente ve las reseñas pero no las modera
        $rosa = $this->as('rosa@acme.com');
        $this->withToken($rosa)->getJson($this->api("/courses/{$this->course}/reviews"))->assertOk()->assertJsonPath('meta.can_moderate', false);
        $this->withToken($rosa)->postJson($this->api("/courses/{$this->course}/reviews/{$review}/show"))->assertForbidden();

        // Quien no está matriculado no opina
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'eva@acme.com']);
        $this->withToken($this->as('eva@acme.com'))->putJson($this->api("/me/courses/{$this->course}/review"), ['rating' => 5])->assertNotFound();
    }

    public function test_the_academy_answers_a_review_in_public(): void
    {
        $this->withToken($this->as('ana@acme.com'))->putJson($this->api("/me/courses/{$this->course}/review"), ['rating' => 3, 'comment' => 'Faltan ejemplos.'])->assertOk();

        $admin = $this->as('admin@acme.com');
        $review = $this->withToken($admin)->getJson($this->api("/courses/{$this->course}/reviews"))->json('data.0.id');
        $this->withToken($admin)->putJson($this->api("/courses/{$this->course}/reviews/{$review}/response"), ['response' => 'Gracias, agregamos un módulo de ejemplos.'])
            ->assertOk()
            ->assertJsonPath('data.response', 'Gracias, agregamos un módulo de ejemplos.');

        $this->flushHeaders()->getJson($this->api("/catalog/courses/{$this->course}"))->assertJsonPath('data.reviews.0.response', 'Gracias, agregamos un módulo de ejemplos.');
        $this->withToken($this->as('ana@acme.com'))->getJson($this->api("/me/courses/{$this->course}/review"))->assertJsonPath('data.response', 'Gracias, agregamos un módulo de ejemplos.');

        // El docente no responde en nombre de la academia
        $this->withToken($this->as('rosa@acme.com'))->putJson($this->api("/courses/{$this->course}/reviews/{$review}/response"), ['response' => 'x'])->assertForbidden();
    }
}
