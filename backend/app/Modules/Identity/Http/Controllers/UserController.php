<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\RoleService;
use App\Modules\Identity\Application\UserService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Http\SessionPresenter;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $like = '%'.mb_strtolower($search).'%';

                $query->where(fn ($q) => $q
                    ->whereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like]));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($users, fn (User $user) => SessionPresenter::user($user));
    }

    public function store(Request $request, UserService $users, TenantContext $context): JsonResponse
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
            'password' => ['required', 'string', 'min:8'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'uuid', 'distinct',
                Rule::exists('roles', 'id')->where('tenant_id', $context->tenantId()),
            ],
        ], ['email.unique' => 'Ya existe un usuario con ese correo en la organización.']);

        $user = $users->create($request->user(), $data);

        return ApiResponse::success(SessionPresenter::user($user->load('roles')), null, 201);
    }

    public function update(Request $request, User $user, UserService $users): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ]);

        $user = $users->update($user, $data);

        return ApiResponse::success(SessionPresenter::user($user->load('roles')));
    }

    public function updateRoles(Request $request, User $user, RoleService $roles, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'uuid', 'distinct',
                Rule::exists('roles', 'id')->where('tenant_id', $context->tenantId()),
            ],
        ]);

        $user = $roles->assignToUser($request->user(), $user, $data['roles']);

        return ApiResponse::success(SessionPresenter::user($user->load('roles')));
    }
}
