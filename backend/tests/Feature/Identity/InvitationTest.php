<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Invitación por correo: la persona crea su propia contraseña con un enlace,
 * en vez de que el personal se la invente.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'mi-clave-propia';

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

    /**
     * El enlace del último correo de invitación enviado a esa dirección.
     *
     * @return array<string, string>
     */
    private function invitationLink(string $email): array
    {
        $link = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$link, $email) {
            if ($mail->hasTo($email) && $mail->title === 'Te invitamos a Organización acme') {
                $link = $mail->url;
            }

            return $link !== null;
        });

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return $query;
    }

    private function createWithoutPassword(): string
    {
        $student = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Role::query()->where('code', RoleCatalog::STUDENT)->where('is_system', true)->value('id'));

        return $this->withToken($this->admin)->postJson($this->api('/users'), [
            'first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@correo.com', 'roles' => [$student],
        ])->assertCreated()->assertJson(fn ($json) => $json->where('data.invited_at', fn ($value) => filled($value))->etc())->json('data.id');
    }

    public function test_a_person_created_without_password_creates_it_from_the_invitation(): void
    {
        Mail::fake();
        $this->createWithoutPassword();

        $link = $this->invitationLink('ana@correo.com');
        $this->assertSame('1', $link['invitacion']);

        // Mientras no cree su contraseña no hay forma de entrar
        $this->postJson($this->api('/auth/login'), ['email' => 'ana@correo.com', 'password' => self::PASSWORD])->assertUnauthorized();

        $this->postJson($this->api('/auth/reset-password'), [
            'token' => $link['token'], 'email' => $link['email'], 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson($this->api('/auth/login'), ['email' => 'ana@correo.com', 'password' => self::NEW_PASSWORD])->assertOk()->assertJsonPath('data.user.invited_at', null);
    }

    public function test_the_invitation_is_resent_and_the_old_link_stops_working(): void
    {
        Mail::fake();
        $user = $this->createWithoutPassword();
        $first = $this->invitationLink('ana@correo.com');

        Mail::fake();
        $this->withToken($this->admin)->postJson($this->api("/users/{$user}/invitation"))->assertOk();
        $second = $this->invitationLink('ana@correo.com');
        $this->assertNotSame($first['token'], $second['token']);

        $reset = fn (array $link) => $this->postJson($this->api('/auth/reset-password'), [
            'token' => $link['token'], 'email' => $link['email'], 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ]);
        $reset($first)->assertStatus(422);

        // Vale 7 días
        $this->travel(8)->days();
        $reset($second)->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');
        $this->travelBack();

        $this->withToken($this->admin)->postJson($this->api("/users/{$user}/invitation"))->assertOk();
        $reset($this->invitationLink('ana@correo.com'))->assertOk();

        // Ya creó su contraseña: no hay invitación que reenviar
        $this->withToken($this->login('acme', 'admin@acme.com'))->postJson($this->api("/users/{$user}/invitation"))->assertStatus(422);
    }

    public function test_with_a_password_the_account_is_ready_as_before(): void
    {
        Mail::fake();
        $student = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Role::query()->where('code', RoleCatalog::STUDENT)->where('is_system', true)->value('id'));

        $this->withToken($this->admin)->postJson($this->api('/users'), [
            'first_name' => 'Luis', 'last_name' => 'Vega', 'email' => 'luis@correo.com', 'password' => self::PASSWORD, 'roles' => [$student],
        ])->assertCreated()->assertJsonPath('data.invited_at', null);

        Mail::assertNotSent(PasswordResetMail::class);
        $this->postJson($this->api('/auth/login'), ['email' => 'luis@correo.com', 'password' => self::PASSWORD])->assertOk();
    }
}
