<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\InstallableApp;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manifiesto e ícono de la aplicación instalable de cada academia. Son
 * públicos: el navegador los pide sin sesión para ofrecer «Instalar».
 */
class InstallableAppController extends Controller
{
    public function __construct(private readonly InstallableApp $app) {}

    public function manifest(Request $request): JsonResponse
    {
        return response()
            ->json($this->app->manifest($request->attributes->get('tenant')), 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function icon(Request $request, int $size, ?string $maskable = null): Response
    {
        if (! in_array($size, InstallableApp::SIZES, true)) {
            throw new ApiException(ErrorCode::NotFound, 'El recurso solicitado no existe.', 404);
        }

        return response($this->app->icon($request->attributes->get('tenant'), $size, $maskable !== null), 200, [
            'Content-Type' => 'image/png',
            // La dirección cambia cuando cambia el ícono (?v=), así que se puede guardar un día
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
