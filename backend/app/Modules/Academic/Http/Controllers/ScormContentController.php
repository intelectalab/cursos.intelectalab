<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Application\ScormLaunch;
use App\Modules\Academic\Application\ScormPackageService;
use App\Modules\Academic\Domain\Models\ScormPackage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Archivos de un paquete SCORM para el marco del aula. El permiso viene en
 * el enlace firmado (ScormLaunch): el marco no puede mandar la sesión.
 */
class ScormContentController extends Controller
{
    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'xsd' => 'application/xml',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'vtt' => 'text/vtt',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'pdf' => 'application/pdf',
    ];

    public function __invoke(string $token, string $path, TenantContext $context): Response
    {
        $grant = ScormLaunch::verify($token);
        $path = ScormPackageService::clean($path);

        if ($grant === null || $path === null) {
            throw new NotFoundHttpException;
        }

        $package = $context->runForTenant($grant['tenant'], fn () => ScormPackage::query()->find($grant['package']));
        $file = $package ? $package->directory.'/'.$path : null;

        if ($file === null || ! Storage::disk($package->disk)->exists($file)) {
            throw new NotFoundHttpException;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return Storage::disk($package->disk)->response($file, null, [
            'Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream',
            // Solo dentro del aula de la propia plataforma
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
