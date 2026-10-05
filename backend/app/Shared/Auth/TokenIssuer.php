<?php

namespace App\Shared\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * Emite el token de una sesión con su vencimiento y el dispositivo de origen,
 * para poder listar y revocar sesiones activas.
 */
class TokenIssuer
{
    /**
     * @param  Model  $user  modelo con el trait HasApiTokens
     */
    public static function issue(Model $user): string
    {
        $token = $user->createToken(
            'auth-token',
            ['*'],
            now()->addMinutes(config('identity.session_lifetime_minutes'))
        );

        $token->accessToken->forceFill([
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500) ?: null,
        ])->save();

        return $token->plainTextToken;
    }
}
