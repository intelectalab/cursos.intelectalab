<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\PasswordResetToken;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Tenancy\Application\EmailTemplates;
use App\Modules\Tenancy\Application\TenantMailer;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Invitación por correo: la persona recibe un enlace para crear su propia
 * contraseña, en vez de que el personal se la invente. Usa el mismo enlace
 * de un solo uso que «Restablecer contraseña», con más días de vigencia.
 */
class InvitationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmailTemplates $templates,
        private readonly TenantNotifier $notifier,
        private readonly TenantMailer $mailer,
    ) {}

    /**
     * Envía (o reenvía) la invitación. El correo sale cuando se confirma la transacción.
     */
    public function invite(User $user): void
    {
        if (! $user->isActive()) {
            throw new ApiException(ErrorCode::ValidationError, 'La cuenta está desactivada: actívala antes de invitar.', 422);
        }

        $tenant = $this->notifier->currentTenant();
        $token = Str::random(64);
        $days = config('identity.invitation_days');

        DB::transaction(function () use ($user, $token, $days) {
            // Una invitación nueva invalida los enlaces anteriores
            PasswordResetToken::query()->where('user_id', $user->id)->delete();
            PasswordResetToken::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays($days),
            ]);

            $user->forceFill(['invited_at' => now()])->save();
            $this->audit->record('users.invited', $user, null, ['email' => $user->email]);
        });

        if ($tenant === null) {
            return;
        }

        $url = $this->notifier->origin($tenant).'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email, 'invitacion' => 1]);
        // Es indispensable: la academia puede cambiar el texto, pero no apagarlo
        $content = $this->templates->render('invitacion', ['nombre' => $user->first_name, 'academia' => $tenant->name, 'correo' => $user->email, 'dias' => $days]);
        $mail = new PasswordResetMail(...$this->notifier->parts($tenant, $user->first_name, $content, $url), url: $url);

        DB::afterCommit(function () use ($tenant, $user, $mail) {
            try {
                $this->mailer->deliver($tenant, $user->email, $mail);
            } catch (Throwable $error) {
                Log::warning("No se pudo enviar la invitación a {$user->email}: ".$error->getMessage());
            }
        });
    }

    /**
     * Contraseña que nadie conoce, para la cuenta que espera su invitación.
     */
    public static function placeholderPassword(): string
    {
        return Str::random(48);
    }
}
