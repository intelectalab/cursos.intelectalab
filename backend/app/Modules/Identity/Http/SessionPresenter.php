<?php

namespace App\Modules\Identity\Http;

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
            'status' => $user->status,
            'roles' => $user->roles->pluck('name')->values(),
            'role_ids' => $user->roles->pluck('id')->values(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
