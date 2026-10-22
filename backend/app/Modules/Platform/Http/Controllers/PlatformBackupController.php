<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Respaldos semanales para descargar desde la consola (decisión del
 * 2026-10-22: «almacenamiento local, según quien lo descargue»). Los arma
 * deploy/scripts/backup.sh una vez por semana: base de datos y archivos de
 * las academias. La clave de la aplicación (.env) no viaja en la descarga.
 */
class PlatformBackupController extends Controller
{
    /** Nombre que da el script a cada respaldo semanal */
    private const PATTERN = '/^erp-semana-\d{4}-\d{2}-\d{2}\.tar\.gz$/';

    public function index(): JsonResponse
    {
        $directory = config('tenancy.backups_path');
        $files = is_dir($directory) ? glob($directory.'/erp-semana-*.tar.gz') : [];

        $backups = collect($files ?: [])
            ->filter(fn (string $path) => preg_match(self::PATTERN, basename($path)) === 1)
            ->map(fn (string $path) => [
                'name' => basename($path),
                'size' => filesize($path),
                'created_at' => date(DATE_ATOM, (int) filemtime($path)),
            ])
            ->sortByDesc('name')
            ->values();

        return ApiResponse::success($backups, ['configured' => is_dir($directory)]);
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        $path = config('tenancy.backups_path').'/'.$name;

        if (preg_match(self::PATTERN, $name) !== 1 || ! is_file($path)) {
            throw new ApiException(ErrorCode::NotFound, 'Ese respaldo no existe.', 404);
        }

        // Contiene los datos de todas las academias: queda constancia de quién lo bajó
        Log::notice("Respaldo {$name} descargado por {$request->user()->email} desde {$request->ip()}");

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }
}
