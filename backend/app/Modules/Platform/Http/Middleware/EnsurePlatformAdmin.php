<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operaciones globales de la plataforma: solo un administrador de plataforma
 * activo. Recién entonces la petición opera sin acotar por tenant.
 */
class EnsurePlatformAdmin
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof PlatformAdmin || ! $admin->isActive()) {
            throw new AuthenticationException;
        }

        return $this->context->runAsSystem(fn () => $next($request));
    }
}
