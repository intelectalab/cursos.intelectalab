<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Darse de baja de correos: por tipo de correo, desde el enlace del correo,
 * con un clic desde el programa de correo o en «Mi cuenta». Los correos
 * indispensables siempre salen.
 */
class EmailOptOutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $course;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $admin = fn () => $this->withToken($this->as('admin@acme.com'));

        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 100])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $this->ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'email' => 'ana@acme.com']);
        $luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Luis', 'email' => 'luis@acme.com']);
        $admin()->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$this->ana->id, $luis->id]])->assertCreated();
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

    private function announce(string $title): void
    {
        $this->withToken($this->as('admin@acme.com'))
            ->postJson($this->api("/courses/{$this->course}/announcements"), ['title' => $title, 'body' => 'Detalle.', 'notify' => true])
            ->assertCreated();
    }

    private function sentTo(string $email): ?TenantNoticeMail
    {
        return Mail::sent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo($email))->last();
    }

    /**
     * La firma del enlace al pie del correo.
     */
    private function tokenOf(TenantNoticeMail $mail): string
    {
        parse_str((string) parse_url($mail->unsubscribe['url'], PHP_URL_QUERY), $query);

        return $query['t'];
    }

    public function test_the_link_in_the_email_unsubscribes_from_that_kind_of_email_only(): void
    {
        Mail::fake();
        $this->announce('Clase el viernes');

        $mail = $this->sentTo('ana@acme.com');
        $this->assertSame('Dejar de recibir «Anuncios de los docentes»', $mail->unsubscribe['label']);
        $this->assertStringContainsString('/correos?t=', $mail->unsubscribe['url']);
        $this->assertStringContainsString('c=announcements', $mail->unsubscribe['url']);
        $this->assertSame('List-Unsubscribe=One-Click', $mail->headers()->text['List-Unsubscribe-Post']);
        $this->assertStringContainsString('/api/v1/email-preferences/', $mail->headers()->text['List-Unsubscribe']);
        $this->assertStringContainsString('Dejar de recibir', $mail->render());

        // La página del enlace, sin sesión: dirección enmascarada y los tipos de alumno (sin los de trabajo)
        $token = $this->tokenOf($mail);
        $this->flushHeaders()->getJson($this->api("/email-preferences/{$token}"))
            ->assertOk()
            ->assertJsonPath('data.email', 'an•••@acme.com')
            ->assertJsonPath('data.academy', $this->tenant->name)
            ->assertJsonCount(3, 'data.categories')
            ->assertJsonMissing(['key' => 'staff']);

        $this->putJson($this->api("/email-preferences/{$token}"), ['categories' => ['announcements' => false]])
            ->assertOk()
            ->assertJsonFragment(['key' => 'announcements', 'subscribed' => false])
            ->assertJsonFragment(['key' => 'reminders', 'subscribed' => true]);

        // El siguiente anuncio le llega a Luis y no a Ana
        Mail::fake();
        $this->announce('Clase el sábado');
        $this->assertNotNull($this->sentTo('luis@acme.com'));
        $this->assertNull($this->sentTo('ana@acme.com'));

        // Se vuelve a suscribir desde la misma página
        $this->flushHeaders()->putJson($this->api("/email-preferences/{$token}"), ['categories' => ['announcements' => true]])->assertOk();
        Mail::fake();
        $this->announce('Clase el domingo');
        $this->assertNotNull($this->sentTo('ana@acme.com'));
    }

    public function test_one_click_unsubscribe_and_links_that_are_not_valid(): void
    {
        Mail::fake();
        $this->announce('Clase el viernes');
        $token = $this->tokenOf($this->sentTo('ana@acme.com'));

        // Lo que hace Gmail al pulsar «Anular suscripción»: un POST sin sesión
        $this->flushHeaders()->post($this->api("/email-preferences/{$token}/unsubscribe?category=announcements"), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->getJson($this->api("/email-preferences/{$token}"))->assertJsonFragment(['key' => 'announcements', 'subscribed' => false]);
        $this->postJson($this->api("/email-preferences/{$token}/unsubscribe?category=invented"))->assertStatus(422);

        // Una firma cambiada no sirve, ni el enlace de una academia en otra
        $forged = substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a');
        $this->getJson($this->api("/email-preferences/{$forged}"))->assertNotFound();

        $this->provisionTenant('beta', 'admin@beta.com');
        $this->getJson($this->tenantUrl('beta', "/email-preferences/{$token}"))->assertNotFound();
    }

    public function test_essential_emails_have_no_link_and_always_arrive(): void
    {
        Mail::fake();
        $this->withToken($this->as('admin@acme.com'))->putJson($this->api('/auth/email-preferences'), [
            'categories' => ['courses' => false, 'reminders' => false, 'announcements' => false, 'staff' => false],
        ])->assertOk();

        // «Cuenta creada por el personal» es indispensable: sale sin enlace de baja
        $student = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Role::query()->where('code', RoleCatalog::STUDENT)->where('is_system', true)->value('id'));
        $this->withToken($this->as('admin@acme.com'))->postJson($this->api('/users'), [
            'first_name' => 'Eva', 'last_name' => 'Ríos', 'email' => 'eva@acme.com', 'password' => 'Clave-segura-2026', 'roles' => [$student],
        ])->assertCreated();
        $mail = $this->sentTo('eva@acme.com');
        $this->assertNotNull($mail);
        $this->assertNull($mail->unsubscribe);
        $this->assertSame([], $mail->headers()->text);

        $this->withToken($this->as('admin@acme.com'))->putJson($this->api('/auth/email-preferences'), ['categories' => ['invented' => false]])->assertStatus(422);
    }

    public function test_my_account_and_the_student_profile(): void
    {
        // El personal elige también sus avisos de trabajo; el alumno, no
        $this->withToken($this->as('admin@acme.com'))->getJson($this->api('/auth/email-preferences'))->assertOk()->assertJsonCount(4, 'data.categories');
        $this->withToken($this->as('ana@acme.com'))->getJson($this->api('/auth/email-preferences'))->assertOk()->assertJsonCount(3, 'data.categories');

        $this->withToken($this->as('ana@acme.com'))->putJson($this->api('/auth/email-preferences'), ['categories' => ['reminders' => false, 'staff' => false]])
            ->assertOk()
            ->assertJsonFragment(['key' => 'reminders', 'subscribed' => false]);

        // La ficha del alumno muestra de qué se dio de baja
        $this->withToken($this->as('admin@acme.com'))->getJson($this->api("/students/{$this->ana->id}"))
            ->assertOk()
            ->assertJsonPath('data.student.email_opt_outs', ['Recordatorios']);
    }
}
