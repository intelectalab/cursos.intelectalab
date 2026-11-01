<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\PlatformBackup;
use Illuminate\Console\Command;

class RunRequestedBackupCommand extends Command
{
    protected $signature = 'platform-backups:run';

    protected $description = 'Hace el respaldo que se pidió desde la consola («Respaldo ahora»), si hay uno pendiente';

    public function handle(PlatformBackup $backup): int
    {
        $result = $backup->runRequested();

        match ($result) {
            null => $this->info('No hay respaldos pedidos.'),
            true => $this->info('Respaldo hecho.'),
            false => $this->error('El respaldo falló: el motivo quedó en el historial de la consola.'),
        };

        return $result === false ? self::FAILURE : self::SUCCESS;
    }
}
