<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Matrículas de un curso, para el personal de la organización.
 */
class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(Request $request, Course $course): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([Enrollment::STATUS_ACTIVE, Enrollment::STATUS_CANCELLED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $enrollments = Enrollment::query()
            ->where('course_id', $course->id)
            ->with('student')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw("status = 'ACTIVE' desc")
            ->orderByDesc('enrolled_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($enrollments, fn (Enrollment $enrollment) => $this->present($enrollment));
    }

    public function candidates(Request $request, Course $course): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return ApiResponse::success(
            $this->enrollments->candidates($course, $filters['search'] ?? null)
                ->take(50)
                ->map(fn (User $user) => $this->student($user))
                ->values()
        );
    }

    public function store(Request $request, Course $course, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['uuid', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $context->tenantId())],
        ]);

        $created = $this->enrollments->enroll($course, $data['student_ids'], $request->user());

        return ApiResponse::success(
            $created->map(fn (Enrollment $enrollment) => $this->present($enrollment->load('student')))->values(),
            ['created' => $created->count(), 'already_enrolled' => count($data['student_ids']) - $created->count()],
            201
        );
    }

    public function cancel(Course $course, string $enrollment): JsonResponse
    {
        $record = Enrollment::query()->where('course_id', $course->id)->with('student')->findOrFail($enrollment);

        return ApiResponse::success($this->present($this->enrollments->cancel($record)));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'status' => $enrollment->status,
            'source' => $enrollment->source,
            'enrolled_at' => $enrollment->enrolled_at->toIso8601String(),
            'cancelled_at' => $enrollment->cancelled_at?->toIso8601String(),
            'student' => $this->student($enrollment->student),
        ];
    }

    /**
     * @return array{id: string, name: string, email: string}
     */
    private function student(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
        ];
    }
}
