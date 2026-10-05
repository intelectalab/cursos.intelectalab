<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\AuthenticateUser;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\SessionPresenter;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticateUser $authenticate): JsonResponse
    {
        [$user, $token] = $authenticate->handle(
            $request->validated('email'),
            $request->validated('password'),
            $request->ip()
        );

        return ApiResponse::success(SessionPresenter::make($user, $token));
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
