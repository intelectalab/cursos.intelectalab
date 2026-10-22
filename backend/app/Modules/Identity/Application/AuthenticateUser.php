<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantSettingsService;
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

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantSettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Con la verificación en dos pasos activa no entrega el token: entrega un
     * paso intermedio que se completa con el código (completeTwoFactor).
     *
     * @return array{0: User, 1: ?string, 2: ?string} usuario, token de acceso y paso intermedio
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

        // Bloqueo de la cuenta: cuenta los fallos seguidos sin importar desde dónde llegan
        $lockKey = self::lockKey($this->context->tenantId(), $email);
        $lockAttempts = (int) config('identity.login_lock_attempts');
        $lockMinutes = (int) config('identity.login_lock_minutes');

        if (RateLimiter::tooManyAttempts($lockKey, $lockAttempts)) {
            $seconds = RateLimiter::availableIn($lockKey);
            $minutes = (int) ceil($seconds / 60);

            throw new ApiException(
                ErrorCode::AccountLocked,
                "La cuenta está bloqueada por demasiados intentos fallidos. Intente nuevamente en {$minutes} minutos o recupere su contraseña.",
                429,
                ['retry_after' => $seconds]
            );
        }

        // El scope de tenant acota la búsqueda a la organización del subdominio
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            $failures = RateLimiter::hit($lockKey, $lockMinutes * 60);

            // Sin contraseñas: solo el correo intentado
            $this->audit->record('auth.login_failed', 'users', after: ['email' => $email], actorUserId: $user?->id, entityId: $user?->id);
            if ($failures === $lockAttempts) {
                $this->audit->record('auth.locked', 'users', after: ['email' => $email, 'minutes' => $lockMinutes], actorUserId: $user?->id, entityId: $user?->id);
            }

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

        if ($this->twoFactor->enabled($user)) {
            // El bloqueo de la cuenta sigue contando: un código equivocado también es un intento fallido
            return [$user, null, $this->twoFactor->challenge($user)];
        }

        RateLimiter::clear($lockKey);

        return [$user, $this->open($user), null];
    }

    /**
     * Segundo paso: el código de la aplicación o uno de respaldo.
     */
    public function completeTwoFactor(string $challenge, string $code, ?string $ip): array
    {
        $user = $this->twoFactor->userFromChallenge($challenge, $this->context->tenantId());
        $lockKey = self::lockKey($this->context->tenantId(), $user->email);
        $lockAttempts = (int) config('identity.login_lock_attempts');

        if (RateLimiter::tooManyAttempts($lockKey, $lockAttempts)) {
            throw new ApiException(ErrorCode::AccountLocked, 'La cuenta está bloqueada por demasiados intentos fallidos. Intente nuevamente más tarde.', 429, ['retry_after' => RateLimiter::availableIn($lockKey)]);
        }

        if (! $user->isActive()) {
            throw new ApiException(ErrorCode::AccountInactive, 'La cuenta de usuario se encuentra suspendida o inactiva.', 403);
        }

        if (! $this->twoFactor->verify($user, $code)) {
            RateLimiter::hit($lockKey, (int) config('identity.login_lock_minutes') * 60);
            $this->audit->record('auth.two_factor_failed', $user, after: ['ip' => $ip], actorUserId: $user->id);

            throw new ApiException(ErrorCode::TwoFactorInvalid, 'El código no es correcto o ya se usó. Escribe el que muestra ahora tu aplicación, o uno de tus códigos de respaldo.', 401);
        }

        RateLimiter::clear($lockKey);

        return [$user, $this->open($user)];
    }

    private function open(User $user): string
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, actorUserId: $user->id);

        return TokenIssuer::issue($user, $this->settings->sessionMinutes());
    }

    /**
     * Clave del contador de fallos de una cuenta. Recuperar la contraseña la limpia.
     */
    public static function lockKey(?string $tenantId, string $email): string
    {
        return 'login-lock|'.$tenantId.'|'.Str::transliterate(Str::lower(trim($email)));
    }
}
