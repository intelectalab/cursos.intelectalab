<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\AuthenticateUser;
use App\Modules\Identity\Application\RegisterStudent;
use App\Modules\Identity\Application\TwoFactorService;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\SessionPresenter;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticateUser $authenticate): JsonResponse
    {
        [$user, $token, $challenge] = $authenticate->handle(
            $request->validated('email'),
            $request->validated('password'),
            $request->ip()
        );

        // Con verificación en dos pasos falta el código: se devuelve el paso intermedio, no la sesión
        if ($challenge !== null) {
            return ApiResponse::success(['two_factor_required' => true, 'challenge' => $challenge, 'expires_in' => TwoFactorService::CHALLENGE_MINUTES * 60]);
        }

        return ApiResponse::success(SessionPresenter::make($user, $token));
    }

    public function twoFactor(Request $request, AuthenticateUser $authenticate): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:2000'],
            'code' => ['required', 'string', 'max:20'],
        ], [], ['code' => 'código']);

        [$user, $token] = $authenticate->completeTwoFactor($data['challenge'], $data['code'], $request->ip());

        return ApiResponse::success(SessionPresenter::make($user, $token));
    }

    /**
     * Alta de un comprador: queda con el rol Alumno y la sesión iniciada.
     */
    public function register(Request $request, RegisterStudent $register, TenantContext $context): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'email', 'max:180',
                Rule::unique('users', 'email')->where('tenant_id', $context->tenantId()),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Quien se registra por su cuenta acepta los términos y la política de privacidad
            'accept_terms' => ['accepted'],
        ], [
            'email.unique' => 'Ya existe una cuenta con ese correo. Inicia sesión para continuar.',
            'accept_terms.accepted' => 'Debes aceptar los términos y la política de privacidad para crear tu cuenta.',
        ]);

        [$user, $token] = $register->handle($data);

        return ApiResponse::success(SessionPresenter::make($user, $token), null, 201);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(SessionPresenter::make($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null);
    }
}
