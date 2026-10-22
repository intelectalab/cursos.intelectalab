<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Certificates\Application\QrCode;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verificación en dos pasos: además de la contraseña, un código de 6 dígitos
 * de una aplicación del teléfono. Si la persona pierde el teléfono, entra con
 * un código de respaldo, o el administrador se la quita.
 */
class TwoFactorService
{
    /** Minutos que vale el paso intermedio entre la contraseña y el código */
    public const CHALLENGE_MINUTES = 5;

    private const RECOVERY_CODES = 8;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantSettingsService $settings,
    ) {}

    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    /**
     * La academia lo exige a su personal y esta persona aún no lo activó.
     */
    public function setupRequired(User $user): bool
    {
        return ! $this->enabled($user) && $this->settings->requiresTwoFactor() && $this->isStaff($user);
    }

    /**
     * Personal = cualquiera con un rol que no sea solo de alumno o de responsable de empresa.
     */
    public function isStaff(User $user): bool
    {
        return $user->roles->pluck('code')->contains(fn (?string $code) => ! in_array($code, [RoleCatalog::STUDENT, RoleCatalog::COMPANY], true));
    }

    /**
     * Clave nueva para escanear. Queda pendiente hasta que la persona confirme un código.
     *
     * @return array{secret: string, uri: string, qr: string}
     */
    public function start(User $user): array
    {
        if ($this->enabled($user)) {
            throw new ApiException(ErrorCode::Conflict, 'La verificación en dos pasos ya está activa.', 409);
        }

        $secret = Totp::secret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null])->save();

        $uri = Totp::uri($secret, $user->email, $user->tenant->name);

        return [
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => $uri,
            'qr' => 'data:image/png;base64,'.base64_encode(QrCode::png($uri, 6)),
        ];
    }

    /**
     * Primer código correcto: queda activa. Devuelve los códigos de respaldo, que se muestran una sola vez.
     *
     * @return list<string>
     */
    public function confirm(User $user, string $code): array
    {
        if ($this->enabled($user) || blank($user->two_factor_secret)) {
            throw new ApiException(ErrorCode::Conflict, 'Primero inicia la activación para obtener el código QR.', 409);
        }

        if (! $this->checkTotp($user, $code)) {
            throw new ApiException(ErrorCode::TwoFactorInvalid, 'El código no es correcto. Revisa que la hora de tu teléfono esté al día y escribe el código que se ve ahora.', 422);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->audit->record('auth.two_factor_enabled', $user, actorUserId: $user->id);

        return $this->newRecoveryCodes($user);
    }

    public function disable(User $user, string $password): void
    {
        $this->assertPassword($user, $password);
        $this->clear($user);
        $this->audit->record('auth.two_factor_disabled', $user, actorUserId: $user->id);
    }

    /**
     * El administrador se la quita a quien perdió su teléfono y sus códigos de respaldo.
     */
    public function reset(User $user): void
    {
        if (! $this->enabled($user)) {
            throw new ApiException(ErrorCode::Conflict, 'Esta persona no tiene activa la verificación en dos pasos.', 409);
        }

        $this->clear($user);
        // Se cierran sus sesiones: quien tuviera el teléfono perdido ya no entra con ellas
        $user->tokens()->delete();
        $this->audit->record('auth.two_factor_reset', $user);
    }

    /**
     * @return list<string>
     */
    public function regenerate(User $user, string $password): array
    {
        $this->assertPassword($user, $password);

        if (! $this->enabled($user)) {
            throw new ApiException(ErrorCode::Conflict, 'La verificación en dos pasos no está activa.', 409);
        }

        $this->audit->record('auth.two_factor_recovery_regenerated', $user, actorUserId: $user->id);

        return $this->newRecoveryCodes($user);
    }

    /**
     * Paso intermedio del inicio de sesión: dice quién puso bien su contraseña, por unos minutos.
     */
    public function challenge(User $user): string
    {
        return Crypt::encryptString((string) json_encode(['u' => $user->id, 't' => $user->tenant_id, 'e' => now()->addMinutes(self::CHALLENGE_MINUTES)->timestamp]));
    }

    /**
     * La persona del paso intermedio, si sigue vigente y es de esta academia.
     */
    public function userFromChallenge(string $challenge, ?string $tenantId): User
    {
        try {
            $data = json_decode(Crypt::decryptString($challenge), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $data = null;
        }

        if (! is_array($data) || ($data['t'] ?? null) !== $tenantId || ($data['e'] ?? 0) < now()->timestamp) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Pasó mucho tiempo desde que escribiste tu contraseña. Vuelve a iniciar sesión.', 401);
        }

        $user = User::query()->find($data['u']);

        if (! $user || ! $this->enabled($user)) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Vuelve a iniciar sesión.', 401);
        }

        return $user;
    }

    /**
     * Código de la aplicación o uno de respaldo (que se gasta al usarlo).
     */
    public function verify(User $user, string $code): bool
    {
        $code = trim($code);

        if (preg_match('/^\d{6}$/', str_replace(' ', '', $code))) {
            return $this->checkTotp($user, str_replace(' ', '', $code));
        }

        $hash = hash('sha256', Str::upper(str_replace([' ', '-'], '', $code)));
        $codes = $user->two_factor_recovery_codes ?? [];

        if (! in_array($hash, $codes, true)) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();
        $this->audit->record('auth.two_factor_recovery_used', $user, after: ['remaining' => count($codes) - 1], actorUserId: $user->id);

        return true;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * Un código vale una sola vez: quien lo vea por encima del hombro no puede reusarlo.
     */
    private function checkTotp(User $user, string $code): bool
    {
        $step = blank($user->two_factor_secret) ? null : Totp::match($user->two_factor_secret, $code);

        return $step !== null && Cache::add("totp-used|{$user->id}|{$step}", true, 120);
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(User $user): array
    {
        $codes = array_map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)), range(1, self::RECOVERY_CODES));

        $user->forceFill(['two_factor_recovery_codes' => array_map(fn (string $code) => hash('sha256', str_replace('-', '', $code)), $codes)])->save();

        return $codes;
    }

    private function clear(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
    }

    private function assertPassword(User $user, string $password): void
    {
        if (! $user->password || ! Hash::check($password, $user->password)) {
            throw new ApiException(ErrorCode::InvalidCredentials, 'La contraseña no es correcta.', 422);
        }
    }
}
