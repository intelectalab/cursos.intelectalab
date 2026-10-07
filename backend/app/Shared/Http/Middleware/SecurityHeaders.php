<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de la API. Las de la aplicación web (HTML, JS y CSS)
 * las pone el servidor web: ver docs/instalacion-y-despliegue.md.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // No anunciar la versión de PHP (en el servidor, además, expose_php = Off)
        header_remove('X-Powered-By');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Una respuesta JSON nunca debe ejecutarse ni incrustarse como documento
        if ($response instanceof JsonResponse) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            // Las respuestas llevan datos de una sesión: no deben quedar en cachés compartidas
            if (! $response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
                $response->headers->set('Cache-Control', 'no-store, private');
            }
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
