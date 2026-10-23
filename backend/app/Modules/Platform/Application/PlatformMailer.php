<?php

namespace App\Modules\Platform\Application;

use App\Modules\Tenancy\Application\TenantMailer;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Correo de envío de la plataforma, que elige el super administrador en la consola:
 *
 * - Del servidor (PLATFORM): el que está en la configuración del servidor (MAIL_* del .env).
 * - Propio (OWN): un servidor de correo guardado en la consola (Elastic, Gmail u otro).
 *   Manda sobre el del servidor; si falla, el correo sale igual por el del servidor
 *   y el error queda a la vista en la consola.
 *
 * Por aquí salen los correos de planes, los avisos de las academias que usan
 * «El correo de IntelectaLab» y el respaldo cuando falla el correo propio de una academia.
 */
class PlatformMailer
{
    public const MODE_OWN = 'OWN';

    public const MODE_SERVER = 'PLATFORM';

    private const KEY = 'platform_mail';

    /**
     * Lo guardado, sin la contraseña.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $stored = $this->stored();
        $smtp = $stored['smtp'] ?? [];

        return [
            'mode' => $stored['mode'] ?? self::MODE_SERVER,
            'from_name' => $stored['from_name'] ?? null,
            'reply_to' => $stored['reply_to'] ?? null,
            'from_address' => $stored['from_address'] ?? null,
            'host' => $smtp['host'] ?? null,
            'port' => $smtp['port'] ?? null,
            'security' => $smtp['security'] ?? 'tls',
            'username' => $smtp['username'] ?? null,
            'has_password' => filled($smtp['password'] ?? null),
            'last_sent_at' => $stored['last_sent_at'] ?? null,
            'last_error' => $stored['last_error'] ?? null,
            'last_error_at' => $stored['last_error_at'] ?? null,
            'defaults' => [
                'from_name' => (string) config('mail.from.name'),
                'reply_to' => null,
                'platform_address' => (string) config('mail.from.address'),
            ],
            // El del servidor: si es «log», los correos no salen
            'server' => [
                'delivering' => self::serverDelivers(),
                'host' => self::serverDelivers() ? config('mail.mailers.'.config('mail.default').'.host') : null,
                'from_address' => (string) config('mail.from.address'),
            ],
            'delivering' => $this->delivers(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  ya validado
     * @return array<string, mixed>
     */
    public function save(array $data, ?string $actor): array
    {
        $stored = $this->stored() ?? [];
        $before = $stored === [] ? null : $this->summary($stored);
        $smtp = $this->smtp($data, $stored['smtp'] ?? []);

        $next = [
            'mode' => $data['mode'],
            'from_name' => $data['from_name'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'from_address' => $data['from_address'] ?? ($stored['from_address'] ?? null),
            'smtp' => $smtp ?? ($data['mode'] === self::MODE_OWN ? null : ($stored['smtp'] ?? null)),
            'last_sent_at' => $stored['last_sent_at'] ?? null,
            'last_error' => $stored['last_error'] ?? null,
            'last_error_at' => $stored['last_error_at'] ?? null,
            'updated_by' => $actor,
        ];

        // Cambió el servidor: el error anterior ya no dice nada
        if ($this->summary($next) !== $before) {
            $next['last_error'] = null;
            $next['last_error_at'] = null;
        }

        $this->store($next);
        // Ajuste general sin academia: queda quién lo cambió y una línea en el registro del servidor
        Log::info('Correo de envío de la plataforma cambiado por '.($actor ?? '—'), $this->summary($next));

        return $this->current();
    }

    /**
     * Prueba con los datos del formulario, sin guardarlos y sin respaldo.
     *
     * @param  array<string, mixed>  $data
     */
    public function test(array $data, Mailable $mail, string $to): void
    {
        $draft = [
            'mode' => $data['mode'],
            'from_name' => $data['from_name'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'from_address' => $data['from_address'] ?? null,
            'smtp' => $this->smtp($data, $this->stored()['smtp'] ?? []),
        ];

        try {
            $draft['mode'] === self::MODE_OWN ? $this->sendOwn($draft, $to, $mail, null) : $this->sendServer($to, $mail, null, $draft);
        } catch (Throwable $error) {
            throw new ApiException(ErrorCode::BadRequest, 'No se pudo enviar con ese servidor de correo. '.TenantMailer::reason($error), 422);
        }
    }

    /**
     * Envía un correo de la plataforma.
     *
     * @param  string|null  $fromName  nombre del remitente (por ejemplo, el de la academia); null = el de la plataforma
     * @param  string|null  $replyTo  a dónde van las respuestas; null = lo guardado en la consola
     */
    public function send(string $to, Mailable $mail, ?string $fromName = null, ?string $replyTo = null): void
    {
        $stored = $this->stored();

        if (($stored['mode'] ?? null) === self::MODE_OWN && self::complete($stored)) {
            try {
                $this->sendOwn($stored, $to, clone $mail, $fromName, $replyTo);
                $this->store(['last_sent_at' => now()->toIso8601String()] + $stored);

                return;
            } catch (Throwable $error) {
                $this->store(['last_error' => TenantMailer::reason($error), 'last_error_at' => now()->toIso8601String()] + $stored);
                Log::warning('El correo propio de la plataforma falló; sale por el del servidor: '.$error->getMessage());
            }
        }

        $this->sendServer($to, clone $mail, $fromName, $stored, $replyTo);
    }

    /** Si hoy los correos de la plataforma salen de verdad */
    public function delivers(): bool
    {
        $stored = $this->stored();

        return (($stored['mode'] ?? null) === self::MODE_OWN && self::complete($stored)) || self::serverDelivers();
    }

    /** La dirección con la que salen hoy los correos de la plataforma */
    public function fromAddress(): string
    {
        $stored = $this->stored();

        return ($stored['mode'] ?? null) === self::MODE_OWN && self::complete($stored) ? $stored['from_address'] : (string) config('mail.from.address');
    }

    private static function serverDelivers(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendOwn(array $settings, string $to, Mailable $mail, ?string $fromName, ?string $replyTo = null): void
    {
        $name = $fromName ?: ($settings['from_name'] ?: config('mail.from.name'));
        $mail->from($settings['from_address'], $name);
        if ($reply = $replyTo ?: ($settings['reply_to'] ?? null)) {
            $mail->replyTo($reply, $name);
        }

        Mail::build(TenantMailer::transport($settings['smtp'] ?? []))->to($to)->send($mail);
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    private function sendServer(string $to, Mailable $mail, ?string $fromName, ?array $settings, ?string $replyTo = null): void
    {
        $name = $fromName ?: (($settings['from_name'] ?? null) ?: config('mail.from.name'));
        $mail->from(config('mail.from.address'), $name);
        if ($reply = $replyTo ?: ($settings['reply_to'] ?? null)) {
            $mail->replyTo($reply, $name);
        }

        Mail::to($to)->send($mail);
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    private static function complete(?array $settings): bool
    {
        return filled($settings['from_address'] ?? null) && filled($settings['smtp']['host'] ?? null) && filled($settings['smtp']['port'] ?? null);
    }

    /**
     * Datos del servidor propio; la contraseña se conserva si no se escribe otra.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>|null
     */
    private function smtp(array $data, array $current): ?array
    {
        if (blank($data['host'] ?? null)) {
            return null;
        }

        $password = filled($data['password'] ?? null) ? $data['password'] : ($current['password'] ?? null);

        return [
            'host' => trim($data['host']),
            'port' => (int) $data['port'],
            'security' => $data['security'] ?? 'tls',
            'username' => filled($data['username'] ?? null) ? trim($data['username']) : null,
            'password' => filled($data['username'] ?? null) ? $password : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function summary(array $settings): array
    {
        return [
            'mode' => $settings['mode'] ?? null,
            'from_address' => $settings['from_address'] ?? null,
            'host' => $settings['smtp']['host'] ?? null,
            'port' => $settings['smtp']['port'] ?? null,
            'username' => $settings['smtp']['username'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(): ?array
    {
        $value = DB::table('platform_settings')->where('key', self::KEY)->value('value');
        if (! $value) {
            return null;
        }

        $settings = json_decode($value, true);
        if (filled($settings['smtp']['password'] ?? null)) {
            $settings['smtp']['password'] = Crypt::decryptString($settings['smtp']['password']);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function store(array $settings): void
    {
        // La contraseña se guarda cifrada
        if (filled($settings['smtp']['password'] ?? null)) {
            $settings['smtp']['password'] = Crypt::encryptString($settings['smtp']['password']);
        }

        DB::table('platform_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($settings), 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
