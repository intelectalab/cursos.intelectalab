<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\AuthenticatePlatformAdmin;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAuthController extends Controller
{
    public function login(Request $request, AuthenticatePlatformAdmin $authenticate): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        [$admin, $token] = $authenticate->handle($credentials['email'], $credentials['password'], $request->ip());

        return ApiResponse::success(['token' => $token, 'admin' => $this->present($admin)]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(['admin' => $this->present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformAdmin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
        ];
    }
}
