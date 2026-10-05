<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Auth\TokenIssuer;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Inicio de sesión dentro del tenant resuelto por el subdominio.
 */
class AuthenticateUser
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{0: User, 1: string} usuario y token de acceso
     */
    public function handle(string $email, string $password, ?string $ip): array
    {
        $email = Str::lower(trim($email));
        $throttleKey = 'login|'.$this->context->tenantId().'|'.Str::transliterate($email).'|'.$ip;

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw new ApiException(
                ErrorCode::TooManyAttempts,
                "Demasiados intentos fallidos. Intente nuevamente en {$seconds} segundos.",
                429,
                ['retry_after' => $seconds]
            );
        }

        // El scope de tenant acota la búsqueda a la organización del subdominio
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw new ApiException(
                ErrorCode::InvalidCredentials,
                'Las credenciales proporcionadas son incorrectas.',
                401
            );
        }

        if (! $user->isActive()) {
            throw new ApiException(
                ErrorCode::AccountInactive,
                'La cuenta de usuario se encuentra suspendida o inactiva.',
                403
            );
        }

        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();

        return [$user, TokenIssuer::issue($user)];
    }
}
