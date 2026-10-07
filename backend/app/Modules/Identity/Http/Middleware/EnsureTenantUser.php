<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Auth\TokenIssuer;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas de una organización solo aceptan usuarios de esa organización:
 * un token de administrador de plataforma no sirve en un subdominio.
 */
class EnsureTenantUser
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Una cuenta desactivada no opera aunque conserve un token
        if (! $user instanceof User || ! $user->isActive()) {
            throw new AuthenticationException;
        }

        // Acceso de soporte de plataforma: su token no tiene todos los permisos y solo puede leer
        if (! $user->currentAccessToken()->can('*')) {
            if (! $request->isMethodSafe() && ! $request->is('api/v1/auth/logout')) {
                throw new ApiException(
                    ErrorCode::SupportReadOnly,
                    'Estás en modo soporte: puedes consultar, pero no cambiar nada.',
                    403
                );
            }

            // Dura lo que se le dio al crearlo: no se renueva con el uso
            return $next($request);
        }

        // Con contraseña temporal solo puede ver su sesión, cambiar la contraseña y salir
        if ($user->must_change_password && ! $request->is('api/v1/auth/me', 'api/v1/auth/password', 'api/v1/auth/logout')) {
            throw new ApiException(
                ErrorCode::PasswordChangeRequired,
                'Debes cambiar tu contraseña temporal antes de continuar.',
                403
            );
        }

        // La actividad mantiene viva la sesión; sin actividad, vence sola
        TokenIssuer::touch($user->currentAccessToken(), $this->settings->sessionMinutes());

        return $next($request);
    }
}
