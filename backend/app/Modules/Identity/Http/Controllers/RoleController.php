<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\RoleService;
use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * Catálogo asignable agrupado por módulo, para armar la matriz rol × módulo.
     */
    public function permissions(): JsonResponse
    {
        $modules = collect(PermissionCatalog::modules())->map(fn (array $module, string $key) => [
            'module' => $key,
            'name' => $module['name'],
            'permissions' => collect($module['permissions'])
                ->map(fn (string $description, string $code) => ['code' => $code, 'description' => $description])
                ->values(),
        ])->values();

        return ApiResponse::success($modules);
    }

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($roles->map(fn (Role $role) => $this->present($role)));
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->user(), $request->validated());

        return ApiResponse::success($this->present($role), null, 201);
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roles->update($request->user(), $role, $request->validated());

        return ApiResponse::success($this->present($role));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roles->delete($role);

        return ApiResponse::success(null);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Role $role): array
    {
        $role->loadMissing('permissions')->loadCount('users');

        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'status' => $role->status,
            'is_system' => $role->is_system,
            'permissions' => $role->permissions->pluck('code')->sort()->values(),
            'users_count' => $role->users_count,
        ];
    }
}
