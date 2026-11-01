<?php

namespace App\Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Genera las llaves VAPID de los avisos al teléfono. Se corre una sola vez
 * por servidor y se copian al .env: si se cambian, todos los dispositivos
 * tienen que volver a activar los avisos. Nunca van al repositorio.
 */
class GenerateWebPushKeysCommand extends Command
{
    protected $signature = 'webpush:keys';

    protected $description = 'Genera las llaves VAPID de los avisos al teléfono para copiarlas al .env (una sola vez por servidor)';

    public function handle(): int
    {
        if (filled(config('services.webpush.private_key'))) {
            $this->warn('Este servidor ya tiene llaves. Si las cambias, todos tendrán que volver a activar los avisos.');
        }

        $keys = VAPID::createVapidKeys();

        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
