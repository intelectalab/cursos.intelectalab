<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\PasswordResetToken;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Recuperación y cambio de contraseña (RF-AUTH-001).
 */
class PasswordService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Envía el enlace de recuperación si el correo pertenece a una cuenta
     * activa. No revela si la cuenta existe.
     *
     * @param  string  $origin  origen del subdominio de la organización (esquema + host)
     */
    public function requestReset(Tenant $tenant, string $email, string $origin): void
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if (! $user || ! $user->isActive()) {
            return;
        }

        $token = Str::random(64);
        $minutes = config('identity.password_reset_minutes');

        DB::transaction(function () use ($user, $token, $minutes) {
            // Un enlace nuevo invalida los anteriores
            PasswordResetToken::query()->where('user_id', $user->id)->delete();

            PasswordResetToken::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes($minutes),
            ]);

            $this->audit->record('auth.password_reset_requested', $user, actorUserId: $user->id);
        });

        $url = $origin.'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);

        Mail::to($user->email)->send(new PasswordResetMail($user, $tenant->name, $url, $minutes));
    }

    public function reset(string $email, string $token, string $password): void
    {
        $reset = PasswordResetToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->whereRelation('user', 'email', Str::lower(trim($email)))
            ->first();

        if (! $reset || ! $reset->user->isActive()) {
            throw new ApiException(
                ErrorCode::InvalidResetToken,
                'El enlace no es válido o ya venció. Solicita uno nuevo.',
                422
            );
        }

        DB::transaction(function () use ($reset, $password) {
            $user = $reset->user;

            $user->update(['password' => $password]);
            $reset->update(['used_at' => now()]);
            // Quien recupera el acceso cierra todas las sesiones abiertas
            $user->tokens()->delete();

            $this->audit->record('auth.password_reset', $user, actorUserId: $user->id);
        });
    }

    /**
     * Cambio de contraseña por el propio usuario. Cierra sus otras sesiones.
     */
    public function change(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw new ApiException(
                ErrorCode::InvalidCredentials,
                'La contraseña actual no es correcta.',
                422,
                ['current_password' => ['La contraseña actual no es correcta.']]
            );
        }

        DB::transaction(function () use ($user, $newPassword) {
            $user->update(['password' => $newPassword]);
            $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

            $this->audit->record('auth.password_changed', $user);
        });
    }
}
