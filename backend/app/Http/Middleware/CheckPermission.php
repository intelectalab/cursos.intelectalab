<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissionCode): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        // Consultar si alguno de los roles del usuario tiene el código de permiso
        $hasPermission = $user->roles()
            ->whereHas('permissions', function ($query) use ($permissionCode) {
                $query->where('code', $permissionCode);
            })
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'status' => 'error',
                'message' => "Acceso denegado: Requiere el permiso [{$permissionCode}]."
            ], 403);
        }

        return $next($request);
    }
}