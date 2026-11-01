<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\BackupLog;
use App\Modules\Platform\Application\PlatformBackup;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Respaldos en la consola de la plataforma (decisión del 2026-10-22:
 * «almacenamiento local, según quien lo descargue»). El script diario del
 * servidor (deploy/scripts/backup.sh) copia la base y los archivos cada
 * noche y deja uno semanal para descargar; desde aquí se ve el historial,
 * el estado, se descargan los semanales y los manuales, y se pide uno en
 * el momento. La restauración sigue por el script del servidor
 * (deploy/scripts/restore.sh): restaurar toda la base afecta a todas las academias.
 */
class PlatformBackupController extends Controller
{
    public function __construct(
        private readonly BackupLog $log,
        private readonly PlatformBackup $backup,
    ) {}

    public function index(TenantContext $context): JsonResponse
    {
        $directory = config('tenancy.backups_path');
        $files = is_dir($directory) ? glob($directory.'/erp-*.tar.gz') : [];

        $backups = collect($files ?: [])
            ->filter(fn (string $path) => preg_match(PlatformBackup::FILE_PATTERN, basename($path)) === 1)
            ->map(fn (string $path) => [
                'name' => basename($path),
                'kind' => str_starts_with(basename($path), 'erp-manual-') ? BackupLog::KIND_MANUAL : BackupLog::KIND_WEEKLY,
                'size' => filesize($path),
                'created_at' => date(DATE_ATOM, (int) filemtime($path)),
            ])
            // Del más reciente al más antiguo; a igual hora, por nombre (lleva la fecha)
            ->sortByDesc(fn (array $backup) => $backup['created_at'].$backup['name'])
            ->values();

        $lastSuccess = $this->log->lastSuccess();

        return ApiResponse::success($backups, [
            'configured' => is_dir($directory),
            // Cada respaldo, bueno o fallido: los diarios del servidor, los semanales y los pedidos desde aquí
            'history' => $this->log->recent(30),
            'status' => [
                'last_success_at' => $lastSuccess?->toIso8601String(),
                'healthy' => $lastSuccess !== null && $lastSuccess->gte(now()->subHours(BackupLog::MAX_HOURS_WITHOUT_BACKUP)),
                'max_hours' => BackupLog::MAX_HOURS_WITHOUT_BACKUP,
                'request' => $this->backup->state(),
                'database_bytes' => $this->databaseSize(),
                'tenants' => Tenant::query()->count(),
                'students' => $context->runAsSystem(fn () => User::query()->whereHas('roles', fn ($roles) => $roles->where('code', RoleCatalog::STUDENT))->count()),
                'keep_manual' => PlatformBackup::KEEP_MANUAL,
            ],
        ]);
    }

    /**
     * «Respaldo ahora»: queda pedido y el servidor lo hace en el próximo minuto.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->backup->request($request->user()->email)) {
            throw new ApiException(ErrorCode::Conflict, 'Ya hay un respaldo en curso. Espera a que termine.', 409);
        }

        Log::notice("Respaldo manual pedido por {$request->user()->email} desde {$request->ip()}");

        return ApiResponse::success(['request' => $this->backup->state()], null, 202);
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        $path = config('tenancy.backups_path').'/'.$name;

        if (preg_match(PlatformBackup::FILE_PATTERN, $name) !== 1 || ! is_file($path)) {
            throw new ApiException(ErrorCode::NotFound, 'Ese respaldo no existe.', 404);
        }

        // Contiene los datos de todas las academias: queda constancia de quién lo bajó
        Log::notice("Respaldo {$name} descargado por {$request->user()->email} desde {$request->ip()}");

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }

    private function databaseSize(): ?int
    {
        try {
            return (int) DB::selectOne('select pg_database_size(current_database()) as size')->size;
        } catch (\Throwable) {
            return null;
        }
    }
}
