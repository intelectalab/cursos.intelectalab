<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verificación en dos pasos: código de una aplicación además de la
 * contraseña, códigos de respaldo, exigencia de la academia a su personal
 * y el botón del administrador para quitarla.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->rosa = $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'rosa@acme.com']);
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function currentCode(User $user): string
    {
        return Totp::code($user->fresh()->two_factor_secret, Totp::step());
    }

    /**
     * Activa los dos pasos de Rosa y devuelve sus códigos de respaldo.
     *
     * @return list<string>
     */
    private function enable(): array
    {
        $token = $this->login('acme', 'rosa@acme.com');

        $this->withToken($token)->postJson($this->api('/auth/two-factor/start'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'uri', 'qr']]);

        $this->withToken($token)->postJson($this->api('/auth/two-factor/confirm'), ['code' => '000000'])->assertStatus(422);

        $codes = $this->withToken($token)->postJson($this->api('/auth/two-factor/confirm'), ['code' => $this->currentCode($this->rosa)])
            ->assertOk()
            ->assertJsonCount(8, 'data.recovery_codes')
            ->json('data.recovery_codes');

        $this->flushHeaders();
        // El código usado para activar ya no sirve para entrar
        $this->travel(31)->seconds();

        return $codes;
    }

    public function test_after_enabling_it_the_login_asks_for_the_code(): void
    {
        $codes = $this->enable();

        $challenge = $this->postJson($this->api('/auth/login'), ['email' => 'rosa@acme.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonMissingPath('data.token')
            ->json('data.challenge');

        $this->postJson($this->api('/auth/two-factor'), ['challenge' => $challenge, 'code' => '123456'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'TWO_FACTOR_INVALID');

        $code = $this->currentCode($this->rosa);
        $this->postJson($this->api('/auth/two-factor'), ['challenge' => $challenge, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_enabled', true)
            ->assertJsonStructure(['data' => ['token']]);

        // El mismo código no vale dos veces
        $this->flushHeaders()->postJson($this->api('/auth/two-factor'), ['challenge' => $challenge, 'code' => $code])->assertStatus(401);

        // Un código de respaldo sirve una sola vez, con o sin guion
        $this->postJson($this->api('/auth/two-factor'), ['challenge' => $challenge, 'code' => strtolower($codes[0])])->assertOk();
        $this->flushHeaders()->postJson($this->api('/auth/two-factor'), ['challenge' => $challenge, 'code' => $codes[0]])->assertStatus(401);
        $this->postJson($this->api('/auth/two-factor'), ['challenge' => $challenge, 'code' => str_replace('-', '', $codes[1])])->assertOk();

        // El paso intermedio vence a los pocos minutos
        $this->travel(6)->minutes();
        $this->flushHeaders()->postJson($this->api('/auth/two-factor'), ['challenge' => $challenge, 'code' => $codes[2]])->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_it_is_turned_off_with_the_password_or_by_the_admin(): void
    {
        $this->enable();
        $this->assertNotNull($this->rosa->fresh()->two_factor_confirmed_at);

        // El administrador se la quita a quien perdió el teléfono, y sus sesiones se cierran
        $admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($admin)->deleteJson($this->api("/users/{$this->rosa->id}/two-factor"))->assertOk();
        $this->assertNull($this->rosa->fresh()->two_factor_confirmed_at);
        $this->withToken($admin)->deleteJson($this->api("/users/{$this->rosa->id}/two-factor"))->assertStatus(409);

        // Sin los dos pasos vuelve a entrar con su contraseña
        $this->flushHeaders()->postJson($this->api('/auth/login'), ['email' => 'rosa@acme.com', 'password' => self::PASSWORD])->assertJsonStructure(['data' => ['token']]);

        // La propia persona la apaga con su contraseña
        $this->enable();
        $token = $this->postJson($this->api('/auth/two-factor'), [
            'challenge' => $this->postJson($this->api('/auth/login'), ['email' => 'rosa@acme.com', 'password' => self::PASSWORD])->json('data.challenge'),
            'code' => $this->currentCode($this->rosa),
        ])->json('data.token');
        $this->withToken($token)->deleteJson($this->api('/auth/two-factor'), ['password' => 'otra'])->assertStatus(422);
        $this->withToken($token)->deleteJson($this->api('/auth/two-factor'), ['password' => self::PASSWORD])->assertOk();
        $this->assertNull($this->rosa->fresh()->two_factor_confirmed_at);
    }

    public function test_the_academy_can_require_it_for_its_staff(): void
    {
        $admin = $this->login('acme', 'admin@acme.com');
        $settings = ['name' => $this->tenant->name, 'primary_currency' => 'PEN', 'timezone' => 'America/Lima', 'grade_scale' => 20, 'session_minutes' => 60];

        // Quien la exige debe tenerla activa, para no quedarse fuera
        $this->withToken($admin)->putJson($this->api('/settings'), $settings + ['require_two_factor' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['require_two_factor'], 'details');

        $this->withToken($admin)->postJson($this->api('/auth/two-factor/start'))->assertOk();
        $owner = User::query()->where('email', 'admin@acme.com')->firstOrFail();
        $this->withToken($admin)->postJson($this->api('/auth/two-factor/confirm'), ['code' => $this->currentCode($owner)])->assertOk();

        $this->withToken($admin)->putJson($this->api('/settings'), $settings + ['require_two_factor' => true])
            ->assertOk()->assertJsonPath('data.require_two_factor', true);

        // Guardar la configuración sin mandar el dato no lo apaga
        $this->withToken($admin)->putJson($this->api('/settings'), $settings)->assertJsonPath('data.require_two_factor', true);

        $this->flushHeaders();
        $token = $this->login('acme', 'rosa@acme.com');
        $this->withToken($token)->getJson($this->api('/auth/me'))->assertJsonPath('data.must_setup_two_factor', true);
        $this->withToken($token)->getJson($this->api('/courses'))->assertForbidden()->assertJsonPath('code', 'TWO_FACTOR_SETUP_REQUIRED');
        $this->withToken($token)->getJson($this->api('/auth/two-factor'))->assertOk()->assertJsonPath('data.required', true);

        // A un alumno no se le exige
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $this->flushHeaders();
        $student = $this->login('acme', 'ana@acme.com');
        $this->withToken($student)->getJson($this->api('/auth/me'))->assertJsonPath('data.must_setup_two_factor', false);
        $this->withToken($student)->getJson($this->api('/me/courses'))->assertOk();
    }
}
