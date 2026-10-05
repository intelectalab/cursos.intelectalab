<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\Tenant;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * Autenticación multi-tenant y emisión de token Sanctum.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Protección contra ataques de fuerza bruta (5 intentos por minuto por IP/Email)
        $throttleKey = Str::transliterate(Str::lower($credentials['email']) . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'code'    => 'TOO_MANY_ATTEMPTS',
                'message' => "Demasiados intentos fallidos. Intente nuevamente en {$seconds} segundos.",
            ], 429);
        }

        // Búsqueda limpia ignorando scopes globales de tenant
        $user = User::withoutGlobalScopes()
            ->with(['tenant', 'roles.permissions'])
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password_hash)) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        // Validación de estado del usuario y de la organización (Null-safe)
        if ($user->status !== 'ACTIVE' || ($user->tenant && $user->tenant->status !== 'ACTIVE')) {
            return response()->json([
                'code'    => 'ACCOUNT_INACTIVE',
                'message' => 'La cuenta de usuario o la organización asociada se encuentra suspendida o inactiva.',
            ], 403);
        }

        // Limpiar contador de intentos fallidos
        RateLimiter::clear($throttleKey);

        // Extracción plana de permisos únicos desde la matriz de roles de la BD
        $permissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('code'))
            ->unique()
            ->values();

        // Actualizar último login de forma segura sin depender de $fillable
        $user->forceFill(['last_login_at' => now()])->save();

        // Emisión de token de acceso vinculado al ID UUID
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'data'   => [
                'token' => $token,
                'user'  => [
                    'id'         => $user->user_id,
                    'email'      => $user->email,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'roles'      => $user->roles->pluck('name')->values(),
                ],
                'tenant' => [
                    'id'   => $user->tenant?->tenant_id,
                    'name' => $user->tenant?->name ?? 'Sistema Central',
                ],
                'permissions' => $permissions,
            ],
        ]);
    }

    /**
     * Devuelve los datos del usuario autenticado en la sesión activa.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['tenant', 'roles.permissions']);

        $permissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('code'))
            ->unique()
            ->values();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'user' => [
                    'id'         => $user->user_id,
                    'email'      => $user->email,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'roles'      => $user->roles->pluck('name')->values(),
                ],
                'tenant' => [
                    'id'   => $user->tenant?->tenant_id,
                    'name' => $user->tenant?->name ?? 'Sistema Central',
                ],
                'permissions' => $permissions,
            ],
        ]);
    }

    /**
     * Invalida el token actual del usuario.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Sesión cerrada exitosamente.',
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:200'],
            'first_name'        => ['required', 'string', 'max:100'],
            'last_name'         => ['required', 'string', 'max:100'],
            'email'             => ['required', 'email', 'max:255', 'unique:erp.app_user,email'],
            'password'          => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $authData = DB::transaction(function () use ($data) {
            // 1. Crear el Tenant aislado
            $tenant = Tenant::create([
                'name'   => $data['organization_name'],
                'status' => 'ACTIVE',
            ]);

            // 2. Crear el rol ADMIN para esta organización
            $adminRole = Role::create([
                'tenant_id'   => $tenant->tenant_id,
                'code'        => 'ADMIN',
                'name'        => 'Administrador',
                'description' => 'Acceso administrativo total de la organización',
                'is_system'   => true,
            ]);

            // 3. Asignar todos los permisos disponibles al nuevo rol
            $allPermissions = Permission::all();
            $adminRole->permissions()->attach($allPermissions->pluck('permission_id'));

            // 4. Crear el usuario Administrador
            $user = User::create([
                'tenant_id'     => $tenant->tenant_id,
                'email'         => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'first_name'    => $data['first_name'],
                'last_name'     => $data['last_name'],
                'status'        => 'ACTIVE',
                'last_login_at' => now(),
            ]);

            // 5. Vincular usuario con su rol
            $user->roles()->attach($adminRole->role_id ?? $adminRole->id);

            // 6. Generar sesión inmediata
            $token = $user->createToken('auth-token')->plainTextToken;

            return [
                'token' => $token,
                'user'  => [
                    'id'         => $user->user_id,
                    'email'      => $user->email,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'roles'      => ['Administrador'],
                ],
                'tenant' => [
                    'id'   => $tenant->tenant_id,
                    'name' => $tenant->name,
                ],
                'permissions' => $allPermissions->pluck('code')->values(),
            ];
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Organización creada exitosamente.',
            'data'    => $authData,
        ], 201);
    }
}