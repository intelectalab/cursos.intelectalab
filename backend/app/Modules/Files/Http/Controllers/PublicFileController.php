<?php

namespace App\Modules\Files\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicFileController extends Controller
{
    /**
     * Archivos públicos de la organización del subdominio (logo, portadas).
     * Un archivo privado o de otra organización responde igual que uno inexistente.
     */
    public function show(string $file, FileStorage $storage): StreamedResponse
    {
        $record = File::query()
            ->whereKey($file)
            ->where('visibility', File::VISIBILITY_PUBLIC)
            ->first();

        if (! $record || ! $storage->exists($record)) {
            throw new ApiException(ErrorCode::NotFound, 'El archivo no existe.', 404);
        }

        $response = $storage->response($record);
        // Cada archivo nuevo tiene otra dirección: el contenido de una dirección no cambia
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }
}
