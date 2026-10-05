<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) $request->headers->get('X-Request-ID', '');

        if (! preg_match('/^[A-Za-z0-9._-]{8,64}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        // Context viaja a los logs y a los jobs encolados.
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
