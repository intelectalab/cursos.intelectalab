<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Modules\Tenancy\Domain\EmailCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Correos automáticos editables: cada academia cambia sus textos o apaga
 * los que no le sirven; la plataforma cambia los suyos y el texto base.
 */
class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    private string $course;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.trial_days' => 365]);
        $this->travelTo('2026-03-02 17:00:00');

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $admin = fn () => $this->withToken($this->admin);
        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 300])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();
    }

    private function api(string $path, string $slug = 'acme'): string
    {
        return $this->tenantUrl($slug, $path);
    }

    private function student(string $email, string $slug = 'acme', ?string $token = null): string
    {
        $token ??= $this->admin;
        $role = collect($this->withToken($token)->getJson($this->api('/roles', $slug))->json('data'))->firstWhere('code', RoleCatalog::STUDENT)['id'];

        return $this->withToken($token)->postJson($this->api('/users', $slug), [
            'email' => $email, 'first_name' => 'Ana', 'last_name' => 'Torres', 'password' => self::PASSWORD, 'roles' => [$role],
        ])->assertCreated()->json('data.id');
    }

    private function enroll(string $studentId): void
    {
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$studentId]])->assertCreated();
    }

    /**
     * @return Collection<int, TenantNoticeMail>
     */
    private function sentTo(string $email)
    {
        return Mail::sent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo($email))->values();
    }

    public function test_an_academy_rewrites_a_mail_and_can_go_back_to_the_original(): void
    {
        $list = $this->withToken($this->admin)->getJson($this->api('/settings/emails'))->assertOk()->json('data');
        $this->assertCount(count(EmailCatalog::ofScope(EmailCatalog::SCOPE_ACADEMY)), $list);
        $this->assertFalse(collect($list)->firstWhere('key', 'matricula')['customized']);

        $this->withToken($this->admin)->getJson($this->api('/settings/emails/matricula'))->assertOk()
            ->assertJsonPath('data.can_disable', true)
            ->assertJsonPath('data.current.subject', 'Ya tienes acceso a «{curso}»')
            ->assertJsonFragment(['name' => 'vigencia', 'description' => 'Fecha hasta la que tiene acceso (vacío si no vence)', 'example' => '30/04/2027', 'essential' => false]);

        $this->withToken($this->admin)->putJson($this->api('/settings/emails/matricula'), [
            'subject' => '¡Bienvenida a {curso}!',
            'body' => "Hola de nuevo. Ya puedes entrar a «{curso}» en {academia}.\n\nTu acceso vence el {vigencia}.",
            'button' => 'Empezar ahora',
            'enabled' => true,
        ])->assertOk()->assertJsonPath('data.customized', true);

        // El curso no vence: el párrafo de la vigencia no se envía
        Mail::fake();
        $this->enroll($this->student('ana@acme.com'));
        $mail = $this->sentTo('ana@acme.com')->first(fn (TenantNoticeMail $mail) => str_contains($mail->title, 'Bienvenida'));
        $this->assertSame('¡Bienvenida a Excel para finanzas!', $mail->title);
        $this->assertSame(['Hola de nuevo. Ya puedes entrar a «Excel para finanzas» en Organización acme.'], $mail->lines);
        $this->assertSame('Empezar ahora', $mail->action['label']);

        // Volver al texto original
        $this->withToken($this->admin)->deleteJson($this->api('/settings/emails/matricula'))->assertOk()
            ->assertJsonPath('data.customized', false)
            ->assertJsonPath('data.current.subject', 'Ya tienes acceso a «{curso}»');
    }

    public function test_texts_are_checked_and_essential_mails_cannot_be_turned_off(): void
    {
        $put = fn (string $key, array $data) => $this->withToken($this->admin)->putJson($this->api("/settings/emails/{$key}"), $data + ['button' => 'Ver', 'enabled' => true]);

        $put('matricula', ['subject' => 'Hola {alumno}', 'body' => 'Curso {curso}'])
            ->assertUnprocessable()
            ->assertJsonPath('details.subject.0', 'En el asunto hay datos que este correo no tiene: {alumno}. Usa solo los de la lista.');
        $put('matricula', ['subject' => 'Hola', 'body' => 'Curso {curso'])->assertUnprocessable()->assertJsonPath('details.body.0', 'En el mensaje falta abrir o cerrar una llave: cada dato va así, {curso}.');
        $put('certificado', ['subject' => 'Tu certificado', 'body' => 'Ya está listo.'])
            ->assertUnprocessable()
            ->assertJsonPath('details.body.0', 'Este correo debe incluir {codigo}: sin ese dato no le sirve a quien lo recibe.');
        $put('cuenta_nueva', ['subject' => 'Cuenta', 'body' => 'Entra con {correo}.', 'enabled' => false])->assertUnprocessable();
        $put('matricula', ['subject' => 'Hola', 'body' => 'Curso {curso}', 'button' => ''])->assertUnprocessable()->assertJsonPath('details.button.0', 'Este correo lleva un botón: escribe su texto.');
    }

    public function test_an_academy_can_turn_off_a_mail_it_does_not_need(): void
    {
        $original = $this->withToken($this->admin)->getJson($this->api('/settings/emails/matricula'))->json('data.current');
        $this->withToken($this->admin)->putJson($this->api('/settings/emails/matricula'), ['enabled' => false] + $original)->assertOk()->assertJsonPath('data.current.enabled', false);

        Mail::fake();
        $this->enroll($this->student('ana@acme.com'));
        // Sale el de la cuenta nueva, pero no el de la matrícula
        $this->assertSame(['Tu cuenta está lista'], $this->sentTo('ana@acme.com')->map(fn ($mail) => $mail->title)->all());
    }

    public function test_preview_and_test_use_example_data_and_reach_only_the_person_asking(): void
    {
        $texts = ['subject' => 'Recibimos tu orden {orden}', 'body' => 'Compraste {curso} por {importe}.', 'button' => 'Ver cómo pagar'];

        $html = $this->withToken($this->admin)->postJson($this->api('/settings/emails/orden_recibida/preview'), $texts)->assertOk()->json('data.html');
        $this->assertStringContainsString('Recibimos tu orden N.º 00042', $html);
        $this->assertStringContainsString('Compraste Excel para finanzas por S/ 120.00.', $html);
        $this->assertStringContainsString('Organización acme', $html);

        Mail::fake();
        $this->withToken($this->admin)->postJson($this->api('/settings/emails/orden_recibida/test'), $texts)->assertOk()->assertJsonPath('data.sent_to', 'admin@acme.com');
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('admin@acme.com') && $mail->title === '[Prueba] Recibimos tu orden N.º 00042');
        Mail::assertSentCount(1);
    }

    public function test_the_platform_sets_the_base_text_and_each_academy_keeps_its_own(): void
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);
        $platform = $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->json('data.token');

        $this->provisionTenant('beta', 'admin@beta.com');
        $beta = $this->login('beta', 'admin@beta.com');

        // Acme tiene su propio asunto; Beta no cambió nada
        $original = $this->withToken($this->admin)->getJson($this->api('/settings/emails/cuenta_nueva'))->json('data.current');
        $this->withToken($this->admin)->putJson($this->api('/settings/emails/cuenta_nueva'), ['subject' => 'Bienvenida a Acme'] + $original)->assertOk();

        $platformList = $this->withToken($platform)->getJson($this->centralUrl('/platform/emails?scope=academy'))->assertOk()->json('data');
        $this->assertCount(count(EmailCatalog::ofScope(EmailCatalog::SCOPE_ACADEMY)), $platformList);
        $this->withToken($platform)->getJson($this->centralUrl('/platform/emails/cuenta_nueva?scope=academy'))->assertJsonPath('data.can_disable', false);
        $this->withToken($platform)->putJson($this->centralUrl('/platform/emails/cuenta_nueva?scope=academy'), ['subject' => 'Te damos la bienvenida'] + $original)->assertOk();

        Mail::fake();
        $this->student('ana@acme.com');
        $this->student('ana@beta.com', 'beta', $beta);
        $this->assertSame('Bienvenida a Acme', $this->sentTo('ana@acme.com')->first()->title);
        $this->assertSame('Te damos la bienvenida', $this->sentTo('ana@beta.com')->first()->title);

        // La academia ve el texto de la plataforma como su texto base
        $this->withToken($beta)->getJson($this->api('/settings/emails/cuenta_nueva', 'beta'))
            ->assertJsonPath('data.base.subject', 'Te damos la bienvenida')
            ->assertJsonPath('data.customized', false);

        // Correos propios de la plataforma
        $this->withToken($platform)->getJson($this->centralUrl('/platform/emails?scope=platform'))->assertOk()->assertJsonCount(count(EmailCatalog::ofScope(EmailCatalog::SCOPE_PLATFORM)), 'data');
        $this->withToken($platform)->getJson($this->centralUrl('/platform/emails/plan_pedido?scope=academy'))->assertNotFound();
    }

    public function test_only_who_manages_emails_can_open_the_screen(): void
    {
        $this->student('ana@acme.com');
        $this->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api('/settings/emails'))->assertForbidden();
        $this->withToken($this->admin)->getJson($this->api('/settings/emails/no_existe'))->assertNotFound();
        $this->withToken($this->admin)->getJson($this->api('/settings/emails/plan_pedido'))->assertNotFound();
        $this->getJson($this->centralUrl('/platform/emails?scope=platform'))->assertUnauthorized();
    }
}
