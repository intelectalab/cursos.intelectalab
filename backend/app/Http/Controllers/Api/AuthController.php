<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::withoutGlobalScopes()
            ->with(['tenant', 'roles.permissions'])
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        if ($user->status !== 'ACTIVE' || $user->tenant->status !== 'ACTIVE') {
            return response()->json([
                'code'    => 'ACCOUNT_INACTIVE',
                'message' => 'La cuenta o la organización se encuentra inactiva.',
            ], 403);
        }

        $permissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('code'))
            ->unique()
            ->values();

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => [
                    'id'         => $user->user_id,
                    'email'      => $user->email,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'roles'      => $user->roles->pluck('name'),
                ],
                'tenant' => [
                    'id'   => $user->tenant->tenant_id,
                    'name' => $user->tenant->name,
                ],
                'permissions' => $permissions,
            ],
        ]);
    }
}