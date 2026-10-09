<?php

namespace App\Modules\Billing\Http\Middleware;

use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organización con la prueba o el plan vencido y los días de gracia
 * agotados: queda en solo lectura. No es una suspensión: todos entran y
 * consultan, y los alumnos siguen estudiando; lo demás no se modifica
 * hasta que la organización pague su plan.
 */
class EnforcePlanAccess
{
    /**
     * Lo que sigue funcionando en solo lectura: entrar y salir, pagar el plan,
     * lo que el alumno hace en sus cursos, el Libro de Reclamaciones, que
     * tiene plazo legal de respuesta, y los avisos de pagos que los
     * compradores ya hicieron en una pasarela.
     */
    private const ALWAYS_ALLOWED = [
        'api/v1/payments/webhooks/*',
        'api/v1/auth/*',
        'api/v1/subscription/*',
        'api/v1/me/*',
        'api/v1/claims',
        'api/v1/claims/*',
    ];

    /** Dentro de lo anterior, lo que sí se detiene: no entran cuentas nuevas */
    private const BLOCKED = [
        'api/v1/auth/register',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('tenant');

        if ($tenant === null || $request->isMethodSafe() || ($request->is(...self::ALWAYS_ALLOWED) && ! $request->is(...self::BLOCKED))) {
            return $next($request);
        }

        $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->first();

        if ($subscription?->access() === TenantSubscription::ACCESS_READ_ONLY) {
            throw new ApiException(
                ErrorCode::PlanReadOnly,
                'La academia está en solo lectura porque su plan venció: se puede consultar todo, pero no registrar cambios hasta renovarlo.',
                403
            );
        }

        return $next($request);
    }
}
