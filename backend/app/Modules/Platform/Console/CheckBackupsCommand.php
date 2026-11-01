<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\BackupLog;
use App\Modules\Platform\Application\PlatformBackup;
use Illuminate\Console\Command;

/**
 * Revisa cada mañana que haya un respaldo bueno reciente. Si el último falló
 * o pasaron más de 48 horas sin uno bueno, avisa a los administradores de la plataforma.
 */
class CheckBackupsCommand extends Command
{
    protected $signature = 'platform-backups:check';

    protected $description = 'Avisa a la plataforma si el último respaldo falló o si hace más de 48 horas que no hay uno bueno';

    public function handle(BackupLog $log, PlatformBackup $backup): int
    {
        $last = $log->recent(1)[0] ?? null;
        $lastSuccess = $log->lastSuccess();
        $problems = [];

        if ($last !== null && $last['ok'] === false) {
            $problems[] = 'El último respaldo ('.$last['started_at'].') falló: '.($last['error'] ?? 'sin detalle').'.';
        }

        if ($lastSuccess === null || $lastSuccess->lt(now()->subHours(BackupLog::MAX_HOURS_WITHOUT_BACKUP))) {
            $problems[] = $lastSuccess === null
                ? 'No hay ningún respaldo bueno registrado.'
                : 'El último respaldo bueno es del '.$lastSuccess->timezone('America/Lima')->format('d/m/Y H:i').': hace más de '.BackupLog::MAX_HOURS_WITHOUT_BACKUP.' horas.';
        }

        if ($problems === []) {
            $this->info('Respaldos al día.');

            return self::SUCCESS;
        }

        $backup->alert($problems);
        $this->warn(implode(' ', $problems));

        return self::SUCCESS;
    }
}
