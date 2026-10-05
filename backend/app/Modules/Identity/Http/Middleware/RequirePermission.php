<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorización server-side por permiso: `permission:courses.publish`.
 */
class RequirePermission
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if (! $user->hasPermission($permission)) {
            $this->audit->record('access.denied', 'permission', after: [
                'permission' => $permission,
                'path' => $request->path(),
            ]);

            throw new ApiException(
                ErrorCode::PermissionDenied,
                'No cuenta con el permiso requerido para esta operación.',
                403,
                ['permission' => $permission]
            );
        }

        return $next($request);
    }
}
