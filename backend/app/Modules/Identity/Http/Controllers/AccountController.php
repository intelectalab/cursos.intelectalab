<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\PasswordService;
use App\Modules\Tenancy\Application\TenantUrls;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Cuenta del propio usuario: contraseña y sesiones activas (RF-AUTH-002).
 */
class AccountController extends Controller
{
    public function forgotPassword(Request $request, PasswordService $passwords): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $passwords->requestReset(
            $request->attributes->get('tenant'),
            $data['email'],
            TenantUrls::base($request->attributes->get('tenant'))
        );

        // Misma respuesta exista o no la cuenta
        return ApiResponse::success(null);
    }

    public function resetPassword(Request $request, PasswordService $passwords): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $passwords->reset($data['email'], $data['token'], $data['password']);

        return ApiResponse::success(null);
    }

    public function changePassword(Request $request, PasswordService $passwords): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], ['password.different' => 'La contraseña nueva debe ser distinta de la actual.']);

        $passwords->change($request->user(), $data['current_password'], $data['password']);

        return ApiResponse::success(null);
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->getKey();

        $sessions = $request->user()->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->getKey(),
                'current' => $token->getKey() === $currentId,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ]);

        return ApiResponse::success($sessions);
    }

    public function revokeSession(Request $request, int $session): JsonResponse
    {
        $request->user()->tokens()->whereKey($session)->firstOrFail()->delete();

        return ApiResponse::success(null);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $request->user()->tokens()
            ->whereKeyNot($request->user()->currentAccessToken()->getKey())
            ->delete();

        return ApiResponse::success(null);
    }
}
