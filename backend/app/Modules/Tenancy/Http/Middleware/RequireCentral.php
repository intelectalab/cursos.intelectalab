<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCentral
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->hasTenant()) {
            throw new ApiException(
                ErrorCode::CentralOnly,
                'Esta operación solo está disponible desde el dominio central de la plataforma.',
                404
            );
        }

        return $next($request);
    }
}
