<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Application\ParticipantService;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Participantes de un curso: alumnos y docentes se agregan desde el mismo
 * lugar, cada uno con su papel en ese curso.
 */
class ParticipantController extends Controller
{
    /** Permiso que hace falta para agregar a alguien con cada papel */
    private const REQUIRED = [
        ParticipantService::ROLE_STUDENT => 'enrollments.create',
        ParticipantService::ROLE_TEACHER => 'courses.update',
    ];

    public function __construct(private readonly ParticipantService $participants) {}

    public function candidates(Request $request, Course $course): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return ApiResponse::success(
            $this->participants->candidates($course, $filters['search'] ?? null)->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim("{$user->first_name} {$user->last_name}"),
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->values(),
            ])->values()
        );
    }

    public function store(Request $request, Course $course, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(ParticipantService::ROLES)],
            'user_ids' => ['required_without:group_ids', 'array', 'max:200'],
            'user_ids.*' => ['uuid', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $context->tenantId())],
            // Grupos completos: entran sus miembros activos que aún no participan en el curso
            'group_ids' => ['required_without:user_ids', 'array', 'max:20'],
            'group_ids.*' => ['uuid', 'distinct', Rule::exists('groups', 'id')->where('tenant_id', $context->tenantId())],
            'starts_at' => ['nullable', 'date_format:Y-m-d'],
            'ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
        ], [
            'ends_at.after_or_equal' => 'El fin del acceso no puede ser anterior al inicio.',
            'user_ids.required_without' => 'Elige al menos una persona o un grupo.',
        ]);

        if (! $request->user()->hasPermission(self::REQUIRED[$data['role']])) {
            throw new ApiException(ErrorCode::PermissionDenied, 'No tienes permiso para agregar participantes con ese papel.', 403);
        }

        $fromGroups = $this->participants->groupMembers($course, $data['group_ids'] ?? []);
        $userIds = array_values(array_unique([...($data['user_ids'] ?? []), ...$fromGroups]));

        if ($userIds === []) {
            return ApiResponse::success(['added' => 0, 'skipped' => 0, 'roles_granted' => 0], null, 201);
        }

        $result = $this->participants->add(
            $course,
            $userIds,
            $data['role'],
            $request->user(),
            isset($data['starts_at']) ? Carbon::parse($data['starts_at'])->startOfDay() : null,
            isset($data['ends_at']) ? Carbon::parse($data['ends_at'])->endOfDay() : null,
        );

        return ApiResponse::success($result, null, 201);
    }

    public function removeTeacher(Course $course, User $user): JsonResponse
    {
        $this->participants->removeTeacher($course, $user);

        return ApiResponse::success(null);
    }
}
