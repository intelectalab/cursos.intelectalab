<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Auth\TokenIssuer;
use Illuminate\Support\Facades\DB;

/**
 * Registro público de un comprador en la organización del subdominio
 * (FP-02): crea la cuenta con el rol Alumno y deja la sesión iniciada.
 * El correo no se verifica todavía: no hay proveedor de correo.
 */
class RegisterStudent
{
    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, phone?: ?string}  $data
     * @return array{0: User, 1: string} usuario y token de acceso
     */
    public function handle(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'email' => $data['email'],
                'password' => $data['password'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'status' => User::STATUS_ACTIVE,
                'last_login_at' => now(),
                'terms_accepted_at' => now(),
            ]);

            $user->roles()->attach(Role::query()->where('code', RoleCatalog::STUDENT)->where('is_system', true)->valueOrFail('id'));

            // El actor es la propia persona que se registra
            $this->audit->record('users.registered', $user, null, $user->only(['email', 'first_name', 'last_name']) + ['terms_accepted' => true], $user->id);

            return $user;
        });

        return [$user, TokenIssuer::issue($user, $this->settings->sessionMinutes())];
    }
}
