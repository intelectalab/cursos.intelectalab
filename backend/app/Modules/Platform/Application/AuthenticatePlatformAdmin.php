<?php

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Shared\Auth\TokenIssuer;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthenticatePlatformAdmin
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * @return array{0: PlatformAdmin, 1: string} administrador y token de acceso
     */
    public function handle(string $email, string $password, ?string $ip): array
    {
        $email = Str::lower(trim($email));
        $throttleKey = 'platform-login|'.Str::transliterate($email).'|'.$ip;

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw new ApiException(
                ErrorCode::TooManyAttempts,
                "Demasiados intentos fallidos. Intente nuevamente en {$seconds} segundos.",
                429,
                ['retry_after' => $seconds]
            );
        }

        $admin = PlatformAdmin::query()->where('email', $email)->first();

        if (! $admin || ! Hash::check($password, $admin->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw new ApiException(
                ErrorCode::InvalidCredentials,
                'Las credenciales proporcionadas son incorrectas.',
                401
            );
        }

        if (! $admin->isActive()) {
            throw new ApiException(ErrorCode::AccountInactive, 'La cuenta se encuentra inactiva.', 403);
        }

        RateLimiter::clear($throttleKey);

        $admin->forceFill(['last_login_at' => now()])->save();

        return [$admin, TokenIssuer::issue($admin)];
    }
}
