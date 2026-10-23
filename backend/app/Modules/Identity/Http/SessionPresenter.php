<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\TwoFactorService;
use App\Modules\Identity\Domain\Models\User;

class SessionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function make(User $user, ?string $token = null): array
    {
        $user->loadMissing(['tenant', 'roles.permissions']);

        $session = [
            'user' => self::user($user),
            'tenant' => [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'slug' => $user->tenant->slug,
            ],
            'permissions' => $user->permissionCodes(),
            // Sesión abierta por el soporte de plataforma: solo lectura
            'support_mode' => ($access = $user->currentAccessToken()) !== null && ! $access->can('*'),
            // La academia exige los dos pasos y esta persona aún no los activó: no puede usar nada más
            'must_setup_two_factor' => app(TwoFactorService::class)->setupRequired($user),
        ];

        return $token !== null ? ['token' => $token] + $session : $session;
    }

    /**
     * @return array<string, mixed>
     */
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'doc_type' => $user->doc_type,
            'doc_number' => $user->doc_number,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name')->values(),
            'role_ids' => $user->roles->pluck('id')->values(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            // Entró con una contraseña temporal: hasta cambiarla no puede usar nada más
            'must_change_password' => (bool) $user->must_change_password,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            // Se le envió una invitación y todavía no crea su contraseña
            'invited_at' => $user->invited_at?->toIso8601String(),
        ];
    }
}
