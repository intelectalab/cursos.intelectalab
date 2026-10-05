<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Matrículas para el personal de la organización: de toda la academia o de
 * un curso concreto.
 */
class EnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly ProgressService $progress,
    ) {}

    /**
     * Todas las matrículas de la organización.
     */
    public function all(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'course_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            ...$this->listRules(),
        ]);

        $query = Enrollment::query()
            ->when($filters['course_id'] ?? null, fn (Builder $q, string $course) => $q->where('course_id', $course))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';

                $q->whereHas('student', fn (Builder $student) => $student
                    ->whereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like]));
            });

        return $this->respond($this->paginate($query, $filters));
    }

    /**
     * Cursos en los que se puede matricular y los que tienen matrículas, para los filtros.
     */
    public function options(): JsonResponse
    {
        $courses = Course::query()
            ->where(fn (Builder $q) => $q
                ->where('status', Course::STATUS_PUBLISHED)
                ->orWhereIn('id', Enrollment::query()->select('course_id')))
            ->orderBy('title')
            ->get(['id', 'title', 'status', 'access_days']);

        return ApiResponse::success([
            'courses' => $courses->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'open' => $course->status === Course::STATUS_PUBLISHED,
                'access_days' => $course->access_days,
            ])->values(),
            'states' => Enrollment::STATES,
        ]);
    }

    /**
     * Matrículas de un curso. La ve quien consulta matrículas y quien hace
     * el seguimiento de ese curso (su profesor).
     */
    public function index(Request $request, Course $course, CourseAccess $access): JsonResponse
    {
        if (! $request->user()->hasPermission('enrollments.read')) {
            $access->assertCanReview($request->user(), $course);
        }

        $filters = $request->validate($this->listRules());

        return $this->respond($this->paginate(Enrollment::query()->where('course_id', $course->id), $filters));
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

    /**
     * Matricular desde la lista general (Maestro §7.2: POST /enrollments).
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'uuid', Rule::exists('courses', 'id')->where('tenant_id', $context->tenantId())],
        ]);

        return $this->storeForCourse($request, Course::query()->findOrFail($data['course_id']), $context);
    }

    public function storeForCourse(Request $request, Course $course, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['uuid', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $context->tenantId())],
            ...$this->validityRules(),
        ]);

        [$startsAt, $endsAt] = $this->validity($data);
        $created = $this->enrollments->enroll($course, $data['student_ids'], $request->user(), $startsAt, $endsAt);
        $created->each->load(['student', 'course.currentVersion']);

        return ApiResponse::success(
            $created->map(fn (Enrollment $enrollment) => $this->present($enrollment))->values(),
            ['created' => $created->count(), 'already_enrolled' => count($data['student_ids']) - $created->count()],
            201
        );
    }

    /**
     * Cambia la vigencia: fechas vacías quitan el límite.
     */
    public function update(Request $request, string $enrollment): JsonResponse
    {
        $data = $request->validate($this->validityRules());
        [$startsAt, $endsAt] = $this->validity($data);

        $record = $this->enrollments->updateValidity($this->find($enrollment), $startsAt, $endsAt);

        return ApiResponse::success($this->present($record));
    }

    public function cancel(string $enrollment): JsonResponse
    {
        return ApiResponse::success($this->present($this->enrollments->cancel($this->find($enrollment))));
    }

    public function cancelForCourse(Course $course, string $enrollment): JsonResponse
    {
        $record = $this->find($enrollment);

        if ($record->course_id !== $course->id) {
            throw new ApiException(ErrorCode::NotFound, 'La matrícula no existe.', 404);
        }

        return ApiResponse::success($this->present($this->enrollments->cancel($record)));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function listRules(): array
    {
        return [
            'state' => ['nullable', Rule::in(Enrollment::STATES)],
            // Compatibilidad: el filtro anterior por estado guardado
            'status' => ['nullable', Rule::in([Enrollment::STATUS_ACTIVE, Enrollment::STATUS_CANCELLED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function validityRules(): array
    {
        return [
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    /**
     * La vigencia se expresa en días completos: desde el inicio del primero hasta el final del último.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function validity(array $data): array
    {
        return [
            ($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at'])->startOfDay() : null,
            ($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at'])->endOfDay() : null,
        ];
    }

    /**
     * @param  Builder<Enrollment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function paginate(Builder $query, array $filters): LengthAwarePaginator
    {
        return $query
            ->with(['student', 'course.currentVersion'])
            ->when($filters['state'] ?? null, fn (Builder $q, string $state) => $q->inState($state))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderByRaw("status = 'ACTIVE' desc")
            ->orderByDesc('enrolled_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);
    }

    /**
     * Agrega el avance: una consulta por curso, no por matrícula.
     */
    private function respond(LengthAwarePaginator $page): JsonResponse
    {
        $progress = collect($page->items())
            ->filter(fn (Enrollment $enrollment) => $enrollment->course->currentVersion !== null)
            ->groupBy('course_id')
            ->flatMap(fn ($group) => $this->progress->summaries($group->first()->course->currentVersion, $group))
            ->all();

        return ApiResponse::paginated($page, fn (Enrollment $enrollment) => $this->present($enrollment) + [
            'progress_percent' => $progress[$enrollment->id]['percent'] ?? 0,
        ]);
    }

    private function find(string $id): Enrollment
    {
        return Enrollment::query()->with(['student', 'course'])->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'status' => $enrollment->status,
            'state' => $enrollment->state(),
            'source' => $enrollment->source,
            'enrolled_at' => $enrollment->enrolled_at->toIso8601String(),
            'starts_at' => $enrollment->starts_at?->toDateString(),
            'ends_at' => $enrollment->ends_at?->toDateString(),
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
            'cancelled_at' => $enrollment->cancelled_at?->toIso8601String(),
            'course' => ['id' => $enrollment->course_id, 'title' => $enrollment->course->title],
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
