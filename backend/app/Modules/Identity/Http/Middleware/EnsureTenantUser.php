<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Auth\TokenIssuer;
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

        // La actividad mantiene viva la sesión; sin actividad, vence sola
        TokenIssuer::touch($user->currentAccessToken(), $this->settings->sessionMinutes());

        return $next($request);
    }
}
