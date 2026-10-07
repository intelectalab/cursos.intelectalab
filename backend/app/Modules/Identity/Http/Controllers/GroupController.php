<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\Group;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Grupos de personas de la organización.
 */
class GroupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $groups = Group::query()->withCount('members')->orderBy('name')->get();

        return ApiResponse::success($groups->map(fn (Group $group) => $this->summary($group))->values());
    }

    public function show(Group $group): JsonResponse
    {
        return ApiResponse::success($this->detail($group));
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $group = Group::create($this->validated($request, $context) + ['created_by' => $request->user()->id]);
        $this->audit->record('groups.created', $group, null, $group->only(['name', 'description']));

        return ApiResponse::success($this->detail($group), null, 201);
    }

    public function update(Request $request, Group $group, TenantContext $context): JsonResponse
    {
        $before = $group->only(['name', 'description']);
        $group->update($this->validated($request, $context, $group));

        if ($group->wasChanged()) {
            $this->audit->record('groups.updated', $group, $before, $group->only(['name', 'description']));
        }

        return ApiResponse::success($this->detail($group));
    }

    /**
     * Borra la lista; las personas y sus matrículas no cambian.
     */
    public function destroy(Group $group): JsonResponse
    {
        $this->audit->record('groups.deleted', $group, $group->only(['name', 'description']) + ['members' => $group->members()->count()]);
        $group->delete();

        return ApiResponse::success(null);
    }

    public function addMembers(Request $request, Group $group, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['uuid', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $context->tenantId())],
        ]);

        $added = count($group->members()->syncWithoutDetaching($data['user_ids'])['attached']);

        if ($added > 0) {
            $this->audit->record('groups.members_added', $group, null, ['group' => $group->name, 'added' => $added]);
        }

        return ApiResponse::success($this->detail($group), ['added' => $added]);
    }

    public function removeMember(Group $group, User $user): JsonResponse
    {
        if ($group->members()->detach($user->id) > 0) {
            $this->audit->record('groups.member_removed', $group, ['group' => $group->name, 'member' => $user->email]);
        }

        return ApiResponse::success($this->detail($group));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Group $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'members_count' => $group->members_count ?? $group->members()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Group $group): array
    {
        $members = $group->members()->orderBy('first_name')->orderBy('last_name')->get();

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'members_count' => $members->count(),
            'members' => $members->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim("{$user->first_name} {$user->last_name}"),
                'email' => $user->email,
                'active' => $user->isActive(),
            ])->values(),
        ];
    }

    /**
     * @return array{name: string, description: ?string}
     */
    private function validated(Request $request, TenantContext $context, ?Group $group = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('groups', 'name')->where('tenant_id', $context->tenantId())->ignore($group)],
            'description' => ['nullable', 'string', 'max:300'],
        ], ['name.unique' => 'Ya existe un grupo con ese nombre.']);
    }
}
