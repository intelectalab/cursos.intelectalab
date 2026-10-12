<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Mail\TenantNoticeMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía un correo de prueba con la plantilla de los avisos, para comprobar
 * la configuración del servidor de correo después de instalar o cambiarla.
 */
class SendTestMailCommand extends Command
{
    protected $signature = 'mail:test {email : Dirección a la que se envía la prueba}';

    protected $description = 'Envía un correo de prueba para comprobar la configuración del correo';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Esa dirección de correo no es válida.');

            return self::FAILURE;
        }

        $this->line('Servidor: '.config('mail.default').' · '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('Remitente: '.config('mail.from.address'));

        try {
            // Directo, sin pasar por la cola: así el error del servidor de correo se ve aquí mismo
            Mail::to($email)->send(new TenantNoticeMail(
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

        $this->info(config('mail.default') === 'log' ? 'El correo está en modo registro: el mensaje quedó en storage/logs, no se envió a nadie.' : "Enviado a {$email}. Revisa también la carpeta de spam.");

        return self::SUCCESS;
    }
}
