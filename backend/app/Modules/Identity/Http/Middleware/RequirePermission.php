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
 * Con varios (`permission:courses.read,courses.assigned.read`) basta uno;
 * el controlador acota después el alcance de cada uno.
 */
class RequirePermission
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $permission = $permissions[0];

        if (! collect($permissions)->contains(fn (string $code) => $user->hasPermission($code))) {
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
