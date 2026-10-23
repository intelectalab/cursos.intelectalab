<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Avisos por correo al personal: le llegan a quien tiene el permiso de
 * atender eso, no a quien lo provocó ni a quien no le toca.
 */
class StaffNoticesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com', 'first_name' => 'Ana', 'last_name' => 'Torres']);
        $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'rosa@acme.com']);
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function sentTo(string $email, string $title): bool
    {
        return Mail::sent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo($email) && $mail->title === $title)->isNotEmpty();
    }

    public function test_a_new_support_question_and_its_follow_up_reach_the_support_staff(): void
    {
        Mail::fake();
        $student = $this->login('acme', 'ana@acme.com');

        $ticket = $this->withToken($student)->postJson($this->api('/tickets'), ['subject' => 'No carga el video', 'category' => 'ACCESS', 'body' => 'Se queda en negro.'])
            ->assertCreated()->json('data.id');

        $this->assertTrue($this->sentTo('admin@acme.com', 'Consulta nueva #0001: No carga el video'));
        $this->assertFalse($this->sentTo('ana@acme.com', 'Consulta nueva #0001: No carga el video'));
        $this->assertFalse($this->sentTo('rosa@acme.com', 'Consulta nueva #0001: No carga el video'));

        // Quien la atendió recibe el mensaje siguiente
        $this->flushHeaders()->withToken($this->login('acme', 'admin@acme.com'))->postJson($this->api("/tickets/{$ticket}/messages"), ['body' => '¿Qué navegador usas?'])->assertSuccessful();
        Mail::fake();
        $this->flushHeaders()->withToken($student)->postJson($this->api("/me/tickets/{$ticket}/messages"), ['body' => 'Chrome.'])->assertSuccessful();
        $this->assertTrue($this->sentTo('admin@acme.com', 'Mensaje nuevo en la consulta #0001'));
    }

    public function test_a_new_claim_reaches_who_answers_the_complaints_book(): void
    {
        Mail::fake();

        $this->postJson($this->api('/claims'), [
            'type' => 'RECLAMO', 'first_name' => 'Rosa', 'last_name' => 'Vargas', 'doc_type' => 'DNI', 'doc_number' => '45678912',
            'address' => 'Av. Arequipa 123, Lima', 'email' => 'rosa@correo.pe', 'phone' => '999 888 777',
            'good_type' => 'SERVICE', 'good_description' => 'Curso Power BI', 'amount' => 150,
            'detail' => 'No tengo acceso.', 'request' => 'Que me activen el curso.', 'accepted' => true,
        ])->assertCreated();

        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('admin@acme.com') && str_starts_with($mail->title, 'Nuevo reclamo LR-'));
        $this->assertFalse(Mail::sent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('ana@acme.com'))->isNotEmpty());
    }

    public function test_the_academy_can_turn_a_staff_notice_off(): void
    {
        Mail::fake();
        $admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($admin)->putJson($this->api('/settings/emails/personal_consulta_nueva'), [
            'subject' => 'Consulta nueva {numero}: {asunto}', 'body' => '{persona} abrió la consulta {numero}.', 'button' => 'Ver la consulta', 'enabled' => false,
        ])->assertOk();

        $this->flushHeaders()->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->api('/tickets'), ['subject' => 'Duda con el curso', 'category' => 'ACCESS', 'body' => 'No encuentro la clase de hoy.'])->assertCreated();

        Mail::assertNotSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => str_starts_with($mail->title, 'Consulta nueva'));
    }
}
