<?php

namespace App\Shared\Auth;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sesiones por token con cierre por inactividad: cada sesión guarda su
 * dispositivo de origen y vence si pasa el tiempo indicado sin actividad.
 */
class TokenIssuer
{
    /**
     * @param  Model  $user  modelo con el trait HasApiTokens
     * @param  int|null  $minutes  minutos de inactividad permitidos (por defecto, el de la plataforma)
     */
    public static function issue(Model $user, ?int $minutes = null): string
    {
        $token = $user->createToken('auth-token', ['*'], now()->addMinutes(self::minutes($minutes)));

        $token->accessToken->forceFill([
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500) ?: null,
        ])->save();

        return $token->plainTextToken;
    }

    /**
     * Renueva el vencimiento con cada uso. Para no escribir en cada petición,
     * solo actualiza cuando ya pasó al menos un minuto desde la última renovación.
     */
    public static function touch(PersonalAccessToken $token, ?int $minutes = null): void
    {
        $expiresAt = now()->addMinutes(self::minutes($minutes));

        if ($token->expires_at === null || $token->expires_at->diffInSeconds($expiresAt, false) >= 60) {
            $token->forceFill(['expires_at' => $expiresAt])->save();
        }
    }

    private static function minutes(?int $minutes): int
    {
        return $minutes ?? config('identity.session_lifetime_minutes');
    }
}
