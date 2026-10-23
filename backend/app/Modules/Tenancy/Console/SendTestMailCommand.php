<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Platform\Application\PlatformMailer;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use Illuminate\Console\Command;
use Throwable;

/**
 * Envía un correo de prueba con la plantilla de los avisos, para comprobar
 * la configuración del servidor de correo después de instalar o cambiarla.
 */
class SendTestMailCommand extends Command
{
    protected $signature = 'mail:test {email : Dirección a la que se envía la prueba}';

    protected $description = 'Envía un correo de prueba para comprobar la configuración del correo';

    public function handle(PlatformMailer $mailer): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Esa dirección de correo no es válida.');

            return self::FAILURE;
        }

        $settings = $mailer->current();
        $this->line($settings['mode'] === PlatformMailer::MODE_OWN
            ? "Servidor: el de la consola ({$settings['host']}:{$settings['port']}); si falla, el del servidor"
            : 'Servidor: el del servidor ('.config('mail.default').' · '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').')');
        $this->line('Remitente: '.$mailer->fromAddress());

        try {
            // Directo, sin pasar por la cola: así el error del servidor de correo se ve aquí mismo
            $mailer->send($email, new TenantNoticeMail(
                organization: (string) config('mail.from.name'),
                logoUrl: null,
                title: 'Prueba de correo',
                greeting: 'Hola:',
                lines: ['Si lees este mensaje, el correo de la plataforma está bien configurado.', 'Enviado el '.now()->format('d/m/Y H:i').' (hora del servidor).'],
            ));
        } catch (Throwable $error) {
            $this->error('No se pudo enviar: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->info(! $mailer->delivers() ? 'La plataforma no tiene correo de envío: el mensaje no se envió a nadie.' : "Enviado a {$email}. Revisa también la carpeta de spam.");

        return self::SUCCESS;
    }
}
