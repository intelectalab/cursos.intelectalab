<?php

namespace App\Modules\Files\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Tenancy\Application\TenantUrls;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicFileController extends Controller
{
    /**
     * Archivos públicos de la organización del subdominio (logo, portadas).
     * Un archivo privado o de otra organización responde igual que uno inexistente.
     *
     * En el modo por ruta todas las organizaciones comparten el dominio y una
     * imagen (<img>) no puede enviar el encabezado X-Tenant: el archivo se
     * busca por su identificador, y solo si es público.
     */
    public function show(string $file, FileStorage $storage, TenantContext $context): StreamedResponse
    {
        $find = fn () => File::query()
            ->whereKey($file)
            ->where('visibility', File::VISIBILITY_PUBLIC)
            ->first();

        $record = match (true) {
            $context->hasTenant() => $find(),
            TenantUrls::pathMode() => $context->runAsSystem($find),
            // Con subdominios, en el dominio central no hay archivos de ninguna organización
            default => throw new ApiException(ErrorCode::TenantRequired, 'Esta operación solo está disponible desde el subdominio de una organización.', 404),
        };

        if (! $record || ! $storage->exists($record)) {
            throw new ApiException(ErrorCode::NotFound, 'El archivo no existe.', 404);
        }

        $response = $storage->response($record);
        // Cada archivo nuevo tiene otra dirección: el contenido de una dirección no cambia
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }
}
