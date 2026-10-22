<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Jobs\SendTenantMail;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Avisos por correo de la academia a sus personas: salen con su nombre y
 * enlaces a su dirección, cuando la operación quedó guardada.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $course;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.trial_days' => 365]);
        // Mediodía en Lima
        $this->travelTo('2026-03-02 17:00:00');

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $admin = fn () => $this->withToken($this->admin);
        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 300, 'access_days' => 30])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * Avisos enviados a esa dirección, por título.
     *
     * @return list<string>
     */
    private function sentTo(string $email): array
    {
        return Mail::sent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo($email))->map(fn (TenantNoticeMail $mail) => $mail->title)->values()->all();
    }

    public function test_a_person_is_told_about_the_account_the_enrollment_and_each_payment(): void
    {
        Mail::fake();

        // Cuenta creada por el personal: se le avisa cómo entrar, sin mandarle la contraseña
        $studentRole = collect($this->withToken($this->admin)->getJson($this->api('/roles'))->json('data'))->firstWhere('code', RoleCatalog::STUDENT)['id'];
        $this->withToken($this->admin)->postJson($this->api('/users'), [
            'email' => 'ana@acme.com', 'first_name' => 'Ana', 'last_name' => 'Torres', 'password' => self::PASSWORD, 'roles' => [$studentRole],
        ])->assertCreated();

        $this->assertSame(['Tu cuenta está lista'], $this->sentTo('ana@acme.com'));
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->organization === $this->tenant->name
            && $mail->greeting === 'Hola Ana:'
            && str_ends_with($mail->action['url'], '://acme.localhost/login')
            && ! str_contains(implode(' ', $mail->lines), self::PASSWORD));

        // Compra en cuotas: orden recibida, cada cuota y el cierre
        $buyer = $this->login('acme', 'ana@acme.com');
        $order = $this->withToken($buyer)->postJson($this->api('/orders'), ['course_id' => $this->course])->assertCreated()->json('data.id');
        $this->withToken($this->admin)->putJson($this->api("/orders/{$order}/installments"), [
            'installments' => [['amount' => 200, 'due_date' => '2026-03-05'], ['amount' => 100, 'due_date' => '2026-04-05']],
        ])->assertOk();

        $confirm = fn () => $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER', 'reference' => 'OP-'.uniqid()])->assertOk();
        $confirm();
        $confirm();

        $this->assertSame(
            ['Tu cuenta está lista', 'Recibimos tu orden N.º 00001', 'Recibimos el pago de tu cuota 1', 'Recibimos el pago de tu cuota 2'],
            $this->sentTo('ana@acme.com')
        );
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->title === 'Recibimos el pago de tu cuota 1'
            && str_contains(implode(' ', $mail->lines), 'Tu siguiente cuota es de S/ 100.00 y vence el 05/04/2026')
            && str_ends_with($mail->action['url'], "://acme.localhost/my-orders/{$order}"));

        // Reembolso
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'El alumno se retiró del curso.'])->assertOk();
        $this->assertContains('Tu compra fue reembolsada', $this->sentTo('ana@acme.com'));

        // Matrícula hecha por el personal a otra persona
        $luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com', 'first_name' => 'Luis']);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$luis->id]])->assertCreated();
        $this->assertSame(['Ya tienes acceso a «Excel para finanzas»'], $this->sentTo('luis@acme.com'));
    }

    public function test_notices_can_go_out_in_the_background_and_the_mail_setup_can_be_checked(): void
    {
        Mail::fake();
        Queue::fake();
        config(['mail.queue' => true]);

        $luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com']);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$luis->id]])->assertCreated();

        // Con la cola activada el aviso no se envía dentro de la petición: va a la cola con la academia
        Queue::assertPushed(SendTenantMail::class, fn (SendTenantMail $job) => $job->to === 'luis@acme.com' && $job->tenantId === $this->tenant->id);
        Mail::assertNotSent(TenantNoticeMail::class);

        // El comando de comprobación envía directo, y rechaza una dirección mal escrita
        $this->artisan('mail:test', ['email' => 'no-es-un-correo'])->assertFailed();
        $this->artisan('mail:test', ['email' => 'soporte@acme.com'])->assertSuccessful();
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('soporte@acme.com') && $mail->title === 'Prueba de correo');
    }

    public function test_an_operation_that_fails_sends_nothing(): void
    {
        Mail::fake();

        $luis = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com']);

        // Un curso que no existe: la matrícula no se hace y no sale ningún aviso
        $this->withToken($this->admin)->postJson($this->api('/courses/00000000-0000-0000-0000-000000000000/enrollments'), ['student_ids' => [$luis->id]])->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_daily_reminders_go_out_on_their_exact_day_only(): void
    {
        $ana = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com', 'first_name' => 'Ana']);
        $buyer = $this->login('acme', 'ana@acme.com');

        // Una compra en cuotas: la segunda vence el 5 de abril. El acceso al curso dura 30 días: hasta el 1 de abril
        $order = $this->withToken($buyer)->postJson($this->api('/orders'), ['course_id' => $this->course])->assertCreated()->json('data.id');
        $this->withToken($this->admin)->putJson($this->api("/orders/{$order}/installments"), [
            'installments' => [['amount' => 200, 'due_date' => '2026-03-05'], ['amount' => 100, 'due_date' => '2026-04-05']],
        ])->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER'])->assertOk();

        $titlesOn = function (string $day) use ($ana): array {
            $this->travelTo("{$day} 17:00:00");
            Mail::fake();
            $this->artisan('reminders:send')->assertSuccessful();

            return $this->sentTo($ana->email);
        };

        $this->assertSame([], $titlesOn('2026-03-20'));
        $this->assertSame(['Tu acceso a «Excel para finanzas» vence en 7 días'], $titlesOn('2026-03-25'));
        $this->assertSame(['Tu cuota 2 vence en 3 días'], $titlesOn('2026-04-02'));
        $this->assertSame([], $titlesOn('2026-04-05'));
        $this->assertSame(['Tu cuota 2 venció ayer'], $titlesOn('2026-04-06'));
        $this->assertSame([], $titlesOn('2026-04-07'));
    }
}
