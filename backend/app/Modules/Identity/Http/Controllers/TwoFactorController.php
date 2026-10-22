<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\TwoFactorService;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Mi cuenta» → Verificación en dos pasos, y el botón del administrador
 * para quitársela a quien perdió su teléfono.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'enabled' => $this->twoFactor->enabled($user),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_left' => $this->twoFactor->recoveryCodesLeft($user),
            'required' => $this->twoFactor->setupRequired($user),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        return ApiResponse::success($this->twoFactor->start($request->user()));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']], [], ['code' => 'código']);

        return ApiResponse::success(['recovery_codes' => $this->twoFactor->confirm($request->user(), preg_replace('/\s+/', '', $data['code']))]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']], [], ['password' => 'contraseña']);

        $this->twoFactor->disable($request->user(), $data['password']);

        return ApiResponse::success(['enabled' => false]);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']], [], ['password' => 'contraseña']);

        return ApiResponse::success(['recovery_codes' => $this->twoFactor->regenerate($request->user(), $data['password'])]);
    }

    public function reset(string $user): JsonResponse
    {
        $this->twoFactor->reset(User::query()->findOrFail($user));

        return ApiResponse::success(['enabled' => false]);
    }
}
