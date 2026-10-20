<?php

namespace Tests\Feature\Shared;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Con el idioma en español (como en el servidor) los errores de cada campo
 * salen como frases, no como su clave («validation.required»).
 */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_errors_are_written_in_spanish(): void
    {
        app()->setLocale('es');
        $this->provisionTenant('acme', 'admin@acme.com');

        $this->postJson($this->tenantUrl('acme', '/auth/login'), ['email' => 'no-es-un-correo'])
            ->assertUnprocessable()
            ->assertJsonPath('details.email.0', 'El correo debe ser un correo electrónico válido.')
            ->assertJsonPath('details.password.0', 'Falta la contraseña.');

        $admin = $this->login('acme', 'admin@acme.com');
        app()->setLocale('es');
        $this->withToken($admin)->getJson($this->tenantUrl('acme', '/dashboard/sales?from=2026-03-14&to=2026-03-08'))
            ->assertUnprocessable()
            ->assertJsonPath('details.to.0', 'La fecha «hasta» debe ser una fecha igual o posterior a la fecha «desde».');
    }
}
