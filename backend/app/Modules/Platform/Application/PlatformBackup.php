<?php

namespace App\Modules\Platform\Application;

use App\Modules\Billing\Mail\PlanOrderMail;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * «Respaldo ahora» de la consola: la base de datos (pg_dump, formato propio)
 * y los archivos de las academias, en un solo archivo para descargar. Es la
 * misma copia que arma el script semanal, hecha en el momento.
 *
 * La consola solo deja el pedido anotado; lo hace el programador de tareas
 * (platform-backups:run, cada minuto), que no tiene el tope de tiempo de
 * una petición web ni el de la cola.
 */
class PlatformBackup
{
    /** Respaldos manuales que se conservan; los más antiguos se borran */
    public const KEEP_MANUAL = 5;

    public const FILE_PATTERN = '/^erp-(semana-\d{4}-\d{2}-\d{2}|manual-\d{4}-\d{2}-\d{2}-\d{4})\.tar\.gz$/';

    /** Pedido pendiente: guarda quién lo pidió */
    private const REQUEST = 'platform-backup-requested';

    /** Mientras hay uno en curso no se arranca otro */
    private const LOCK = 'platform-backup-running';

    public function __construct(
        private readonly BackupLog $log,
        private readonly PlatformMailer $mailer,
    ) {}

    /**
     * PENDING = pedido, espera al programador; RUNNING = en curso; null = nada.
     */
    public function state(): ?string
    {
        return Cache::has(self::LOCK) ? 'RUNNING' : (Cache::has(self::REQUEST) ? 'PENDING' : null);
    }

    /**
     * Anota el pedido. false si ya hay uno pedido o en curso.
     */
    public function request(string $requestedBy): bool
    {
        return $this->state() === null && Cache::add(self::REQUEST, $requestedBy, now()->addHours(2));
    }

    /**
     * Lo llama el programador cada minuto: si hay un pedido, hace el respaldo.
     *
     * @return bool|null null = no había pedido; si no, si salió bien
     */
    public function runRequested(): ?bool
    {
        $requestedBy = Cache::pull(self::REQUEST);

        if ($requestedBy === null || ! Cache::add(self::LOCK, true, now()->addHours(2))) {
            return null;
        }

        return $this->run($requestedBy);
    }

    public function run(?string $requestedBy): bool
    {
        $directory = config('tenancy.backups_path');
        $stamp = now()->format('Y-m-d-Hi');
        $file = "erp-manual-{$stamp}.tar.gz";
        $work = storage_path("app/backup-work-{$stamp}");
        $started = now()->toIso8601String();

        try {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            if (! is_dir($work)) {
                mkdir($work, 0700, true);
            }

            $db = config('database.connections.'.config('database.default'));

            // El usuario de la aplicación no se salta el aislamiento entre academias: activa el permiso que la política reconoce
            $this->must(Process::env(['PGPASSWORD' => (string) $db['password'], 'PGOPTIONS' => '-c app.bypass_rls=on'])
                ->timeout(3600)
                ->run(['pg_dump', '--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'], '--format=custom', '--no-owner', '--enable-row-security', '--file='.$work.'/base.dump', $db['database']]), 'la base de datos');

            $this->must(Process::timeout(3600)->run(['tar', '--create', '--gzip', '--file='.$work.'/archivos.tar.gz', '--directory='.storage_path('app'), 'private']), 'los archivos');
            $this->must(Process::timeout(3600)->run(['tar', '--create', '--gzip', '--file='.$directory.'/'.$file, '--directory='.$work, 'base.dump', 'archivos.tar.gz']), 'el archivo final');
            // Sin el archivo final no hay respaldo, aunque los pasos no hayan dado error
            if (! is_file($directory.'/'.$file)) {
                throw new RuntimeException('No se creó el archivo del respaldo.');
            }
            @chmod($directory.'/'.$file, 0600);

            $this->log->append(['kind' => BackupLog::KIND_MANUAL, 'started_at' => $started, 'finished_at' => now()->toIso8601String(), 'ok' => true, 'size' => filesize($directory.'/'.$file), 'file' => $file, 'by' => $requestedBy]);
            $this->prune($directory);

            return true;
        } catch (Throwable $error) {
            @unlink($directory.'/'.$file);
            $this->log->append(['kind' => BackupLog::KIND_MANUAL, 'started_at' => $started, 'finished_at' => now()->toIso8601String(), 'ok' => false, 'error' => $error->getMessage(), 'by' => $requestedBy]);
            Log::error('El respaldo manual falló: '.$error->getMessage());
            $this->alert(['El respaldo que se pidió desde la consola no se pudo hacer.', 'Motivo: '.$error->getMessage()]);

            return false;
        } finally {
            foreach (glob($work.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($work);
            Cache::forget(self::LOCK);
        }
    }

    /**
     * Aviso a los administradores de la plataforma: un respaldo falló o hace mucho que no hay uno bueno.
     *
     * @param  list<string>  $lines
     */
    public function alert(array $lines): void
    {
        foreach (PlatformAdmin::query()->where('status', PlatformAdmin::STATUS_ACTIVE)->pluck('email') as $email) {
            try {
                $this->mailer->send($email, new PlanOrderMail('Revisa los respaldos de la plataforma', [...$lines, 'Revisa la consola → Respaldos y el registro del servidor (/var/log/erp-backup.log).']));
            } catch (Throwable $error) {
                Log::warning("No se pudo avisar del respaldo a {$email}: ".$error->getMessage());
            }
        }
    }

    private function must(ProcessResult $result, string $what): void
    {
        if ($result->failed()) {
            throw new RuntimeException("No se pudo copiar {$what}: ".trim(mb_substr($result->errorOutput() ?: $result->output(), 0, 300)));
        }
    }

    private function prune(string $directory): void
    {
        $manual = glob($directory.'/erp-manual-*.tar.gz') ?: [];
        rsort($manual);
        foreach (array_slice($manual, self::KEEP_MANUAL) as $old) {
            @unlink($old);
        }
    }
}
