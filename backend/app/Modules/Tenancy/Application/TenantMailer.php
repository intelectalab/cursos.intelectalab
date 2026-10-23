<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Platform\Application\PlatformMailer;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\Models\TenantMailSetting;
use App\Modules\Tenancy\Jobs\SendTenantMail;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía los correos de una academia con su propio correo. Dos formas:
 *
 * - Propio: por el servidor de correo de la academia (su Elastic, Gmail de
 *   empresa u otro), con su dirección como remitente.
 * - De la plataforma: por el correo de IntelectaLab, con el nombre de la
 *   academia y su correo para las respuestas.
 *
 * Si el servidor propio falla, el aviso sale igual por el de la plataforma y
 * el error queda a la vista de la academia: no se pierden avisos.
 */
class TenantMailer
{
    /** Segundos que se espera al servidor de correo de la academia */
    private const TIMEOUT = 15;

    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly PlatformMailer $platform,
    ) {}

    /**
     * Lo guardado por la academia actual, sin la contraseña.
     *
     * @return array<string, mixed>
     */
    public function current(Tenant $tenant): array
    {
        $setting = $this->setting();
        $smtp = $setting?->smtp ?? [];

        return [
            'mode' => $setting?->mode ?? TenantMailSetting::MODE_PLATFORM,
            'from_name' => $setting?->from_name,
            'reply_to' => $setting?->reply_to,
            'from_address' => $setting?->from_address,
            'host' => $smtp['host'] ?? null,
            'port' => $smtp['port'] ?? null,
            'security' => $smtp['security'] ?? 'tls',
            'username' => $smtp['username'] ?? null,
            'has_password' => filled($smtp['password'] ?? null),
            'last_sent_at' => $setting?->last_sent_at?->toIso8601String(),
            'last_error' => $setting?->last_error,
            'last_error_at' => $setting?->last_error_at?->toIso8601String(),
            // Lo que se usa cuando la academia no pone lo suyo
            'defaults' => [
                'from_name' => $tenant->name,
                'reply_to' => $this->settings->contactEmail(),
                'platform_address' => $this->platform->fromAddress(),
            ],
            // Mientras la plataforma no tenga correo de envío, sus correos no salen
            'delivering' => $this->platform->delivers(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  ya validado
     */
    public function save(Tenant $tenant, array $data): array
    {
        $setting = $this->setting() ?? new TenantMailSetting;
        $before = $setting->exists ? $this->summary($setting) : null;

        $setting->fill([
            'mode' => $data['mode'],
            'from_name' => $data['from_name'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'from_address' => $data['mode'] === TenantMailSetting::MODE_OWN ? $data['from_address'] : ($data['from_address'] ?? $setting->from_address),
            'smtp' => $this->smtp($data, $setting),
        ]);

        // Cambió el servidor: el error anterior ya no dice nada
        if ($setting->isDirty(['mode', 'smtp', 'from_address'])) {
            $setting->last_error = null;
            $setting->last_error_at = null;
        }

        $setting->save();

        $this->audit->record('mail_settings.updated', $setting, $before, $this->summary($setting));

        return $this->current($tenant);
    }

    /**
     * Correo de prueba con los datos del formulario, sin guardarlos y sin
     * respaldo: si el servidor propio falla, se informa el motivo.
     *
     * @param  array<string, mixed>  $data
     */
    public function test(Tenant $tenant, array $data, Mailable $mail, string $to): void
    {
        $setting = $this->setting() ?? new TenantMailSetting;
        $draft = new TenantMailSetting([
            'mode' => $data['mode'],
            'from_name' => $data['from_name'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'from_address' => $data['from_address'] ?? null,
            'smtp' => $this->smtp($data, $setting),
        ]);

        try {
            $this->sendWith($draft, $tenant, $to, $mail);
        } catch (Throwable $error) {
            throw new ApiException(ErrorCode::BadRequest, 'No se pudo enviar con tu servidor de correo. '.self::reason($error), 422);
        }
    }

    /**
     * Envía un correo de la academia: en segundo plano si la plataforma lo tiene activado.
     */
    public function deliver(Tenant $tenant, string $to, Mailable $mail): void
    {
        if (config('mail.queue')) {
            SendTenantMail::dispatch($tenant->id, $to, $mail);

            return;
        }

        $this->deliverNow($tenant, $to, $mail);
    }

    public function deliverNow(Tenant $tenant, string $to, Mailable $mail): void
    {
        $setting = $this->setting();

        if ($setting?->mode === TenantMailSetting::MODE_OWN && $this->complete($setting)) {
            try {
                $this->sendWith($setting, $tenant, $to, clone $mail);
                // Un envío bueno deja atrás el error de un corte anterior
                $setting->forceFill(['last_sent_at' => now(), 'last_error' => null, 'last_error_at' => null])->save();

                return;
            } catch (Throwable $error) {
                $setting->forceFill(['last_error' => self::reason($error), 'last_error_at' => now()])->save();
                Log::warning("El correo propio de {$tenant->slug} falló; el aviso sale por el de la plataforma: ".$error->getMessage());
            }
        }

        $this->sendWith(null, $tenant, $to, clone $mail, $setting);
    }

    /**
     * @param  TenantMailSetting|null  $own  servidor propio con el que se envía; null = el de la plataforma
     * @param  TenantMailSetting|null  $setting  datos de la academia para el remitente cuando sale por la plataforma
     */
    private function sendWith(?TenantMailSetting $own, Tenant $tenant, string $to, Mailable $mail, ?TenantMailSetting $setting = null): void
    {
        $setting ??= $own;
        $name = $setting?->from_name ?: $tenant->name;
        $replyTo = $setting?->reply_to ?: ($own ? null : ($setting?->from_address ?: $this->settings->contactEmail()));

        if ($own && $own->mode === TenantMailSetting::MODE_OWN) {
            $mail->from($own->from_address, $name);
            if ($replyTo) {
                $mail->replyTo($replyTo, $name);
            }

            Mail::build(self::transport($own->smtp ?? []))->to($to)->send($mail);

            return;
        }

        // Por el correo de la plataforma, con el nombre de la academia
        $this->platform->send($to, $mail, $name, $replyTo);
    }

    /**
     * @param  array<string, mixed>  $smtp
     * @return array<string, mixed>
     */
    public static function transport(array $smtp): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => ($smtp['security'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'host' => $smtp['host'],
            'port' => (int) $smtp['port'],
            'username' => $smtp['username'] ?? null,
            'password' => $smtp['password'] ?? null,
            'timeout' => self::TIMEOUT,
            // «Sin seguridad»: no intenta pasar a TLS aunque el servidor lo ofrezca
            'auto_tls' => ($smtp['security'] ?? 'tls') !== 'none',
        ];
    }

    /**
     * Datos del servidor propio; la contraseña se conserva si no se escribe otra.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function smtp(array $data, TenantMailSetting $current): ?array
    {
        if (blank($data['host'] ?? null)) {
            return $data['mode'] === TenantMailSetting::MODE_OWN ? null : $current->smtp;
        }

        $password = filled($data['password'] ?? null) ? $data['password'] : ($current->smtp['password'] ?? null);

        return [
            'host' => trim($data['host']),
            'port' => (int) $data['port'],
            'security' => $data['security'] ?? 'tls',
            'username' => filled($data['username'] ?? null) ? trim($data['username']) : null,
            // Sin usuario no hay contraseña que guardar
            'password' => filled($data['username'] ?? null) ? $password : null,
        ];
    }

    private function complete(TenantMailSetting $setting): bool
    {
        return filled($setting->from_address) && filled($setting->smtp['host'] ?? null) && filled($setting->smtp['port'] ?? null);
    }

    private function setting(): ?TenantMailSetting
    {
        return TenantMailSetting::query()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(TenantMailSetting $setting): array
    {
        return [
            'mode' => $setting->mode,
            'from_address' => $setting->from_address,
            'host' => $setting->smtp['host'] ?? null,
            'port' => $setting->smtp['port'] ?? null,
            'username' => $setting->smtp['username'] ?? null,
        ];
    }

    /**
     * El motivo del fallo en palabras simples, con el detalle técnico al final para el proveedor.
     */
    public static function reason(Throwable $error): string
    {
        $detail = mb_substr(trim(preg_replace('/\s+/', ' ', $error->getMessage())), 0, 300);

        $explanation = match (true) {
            str_contains($detail, 'Connection could not be established'), str_contains($detail, 'stream_socket_client') => 'No se pudo conectar con el servidor. Revisa el servidor, el puerto y la seguridad.',
            str_contains($detail, '535'), stripos($detail, 'authenticat') !== false => 'El servidor rechazó el usuario o la contraseña.',
            stripos($detail, 'certificate') !== false, stripos($detail, 'ssl') !== false, stripos($detail, 'tls') !== false => 'Falló la conexión segura. Prueba otra opción de «Seguridad» o el puerto que indica tu proveedor.',
            default => 'El servidor de correo respondió con un error.',
        };

        return "{$explanation} Detalle: «{$detail}»";
    }
}
