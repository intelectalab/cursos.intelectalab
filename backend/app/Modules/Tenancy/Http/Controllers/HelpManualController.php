<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * El manual de uso por rol en PDF, para el Centro de ayuda y el panel de
 * autoayuda. Es la versión de entrega (sin las contraseñas del ambiente de
 * pruebas): docs/manual genera el Word y se exporta a PDF. El del servidor
 * (storage/app/manual) manda sobre el que viaja con el código.
 */
class HelpManualController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $path = collect([storage_path('app/manual/manual-de-uso.pdf'), resource_path('manual/manual-de-uso.pdf')])->first(fn (string $file) => is_file($file));

        if ($path === null) {
            throw new ApiException(ErrorCode::NotFound, 'El manual todavía no está disponible. Mientras tanto, usa la ayuda de cada pantalla.', 404);
        }

        return response()->download($path, 'Manual de uso.pdf', ['Content-Type' => 'application/pdf']);
    }
}
