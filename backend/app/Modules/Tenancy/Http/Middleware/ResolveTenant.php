<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Application\TenantUrls;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tenant antes de autenticar: por el subdominio o, en el modo por
 * ruta, por el encabezado X-Tenant (la aplicación web) o por ?tenant= (los
 * avisos de las pasarelas). Sin ninguno de los dos se está en el dominio
 * central: no hay tenant y no se expone ningún dato de tenant.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previous = $this->context->snapshot();

        try {
            $slug = $this->slugFromHost($request->getHost()) ?? $this->slugFromPath($request);

            if ($slug === null) {
                $this->context->clear();

                return $next($request);
            }

            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant) {
                throw new ApiException(ErrorCode::TenantNotFound, 'La organización no existe.', 404);
            }

            if (! $tenant->isActive()) {
                throw new ApiException(ErrorCode::TenantInactive, 'La organización se encuentra suspendida o inactiva.', 403);
            }

            $this->context->setTenant($tenant->id);
            $request->attributes->set('tenant', $tenant);

            return $next($request);
        } finally {
            $this->context->restore($previous);
        }
    }

    /**
     * Modo por ruta: la organización llega aparte, porque todas comparten el
     * dominio central. Elegir otra con el encabezado no da acceso a nada: la
     * sesión de una persona solo vale en su propia organización.
     */
    private function slugFromPath(Request $request): ?string
    {
        if (! TenantUrls::pathMode()) {
            return null;
        }

        $slug = strtolower(trim((string) ($request->headers->get('X-Tenant') ?: $request->query('tenant', ''))));

        return preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) === 1 ? $slug : null;
    }

    private function slugFromHost(string $host): ?string
    {
        $host = strtolower($host);
        $suffix = '.'.strtolower((string) config('tenancy.central_domain'));

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        return substr($host, 0, -strlen($suffix));
    }
}
