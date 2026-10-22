<?php

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Application\CourseService;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseResource;
use App\Modules\Courses\Http\CoursePresenter;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\StateCounts;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function __construct(
        private readonly CourseService $courses,
        private readonly CourseAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Course::STATUSES)],
            'category' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = $this->access->scope(Course::query(), $request->user())
            ->with(['cover', 'currentVersion', 'teachers'])
            ->withCount(['sections', 'resources'])
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereRaw('lower(title) like ?', ['%'.mb_strtolower($search).'%']));

        // Cuántas hay en cada estado con la búsqueda actual: alimenta las tarjetas que filtran
        $counts = StateCounts::of($base, Course::STATUSES, fn ($query, string $status) => $query->where('status', $status));

        $courses = $base
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 24);

        return ApiResponse::paginated($courses, fn (Course $course) => CoursePresenter::summary($course), ['counts' => $counts]);
    }

    /**
     * Catálogos que usan los formularios de cursos.
     */
    public function options(Request $request, TenantSettingsService $settings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $options = [
            'levels' => Course::LEVELS,
            'languages' => Course::LANGUAGES,
            'categories' => $this->access->scope(Course::query(), $user)
                ->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'resource_types' => CourseResource::TYPES,
            // Escala en la que la academia escribe las notas (la nota mínima del curso)
            'grade_scale' => $settings->gradeScale(),
            'cover' => [
                'max_kb' => config('files.limits.image'),
                'extensions' => config('files.purposes.'.CourseService::COVER_PURPOSE.'.extensions'),
            ],
            'material' => [
                'max_kb' => config('files.limits.document'),
                'extensions' => config('files.purposes.'.CourseResource::FILE_PURPOSE.'.extensions'),
            ],
        ];

        // Quien asigna profesores ve a los usuarios activos que pueden dictar
        if ($user->hasPermission('courses.update')) {
            $options['teachers'] = User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->with('roles.permissions')
                ->orderBy('first_name')->orderBy('last_name')
                ->get()
                ->filter(fn (User $candidate) => $candidate->hasPermission('courses.assigned.read'))
                ->map(fn (User $candidate) => CoursePresenter::teacher($candidate))
                ->values();
        }

        return ApiResponse::success($options);
    }

    public function store(Request $request): JsonResponse
    {
        $course = $this->courses->create($request->user(), $this->validated($request));

        return ApiResponse::success(CoursePresenter::detail($course), null, 201);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->access->assertCanView($request->user(), $course);

        return ApiResponse::success(CoursePresenter::detail($course), [
            'can_edit_content' => $this->access->canEditContent($request->user(), $course),
            'can_manage_activities' => $this->access->canManageActivities($request->user(), $course),
            'can_review' => $this->access->canReview($request->user(), $course),
        ]);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        return ApiResponse::success(CoursePresenter::detail($this->courses->update($course, $this->validated($request))));
    }

    public function uploadCover(Request $request, Course $course, FileStorage $storage): JsonResponse
    {
        $request->validate(['file' => $storage->rules(CourseService::COVER_PURPOSE)]);

        $course = $this->courses->replaceCover($course, $request->file('file'));

        return ApiResponse::success(['cover_url' => $course->fresh()->cover?->publicUrl()]);
    }

    public function removeCover(Course $course): JsonResponse
    {
        $this->courses->removeCover($course);

        return ApiResponse::success(['cover_url' => null]);
    }

    public function setTeachers(Request $request, Course $course, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:50'],
            'user_ids.*' => [
                'uuid', 'distinct',
                Rule::exists('users', 'id')->where('tenant_id', $context->tenantId())->where('status', User::STATUS_ACTIVE),
            ],
        ]);

        return ApiResponse::success(CoursePresenter::detail($this->courses->setTeachers($course, $data['user_ids'])));
    }

    public function publish(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        $this->courses->publish($course, $request->user(), $data['note'] ?? null);

        return ApiResponse::success(CoursePresenter::detail($course->fresh()));
    }

    public function archive(Course $course): JsonResponse
    {
        return ApiResponse::success(CoursePresenter::detail($this->courses->archive($course)));
    }

    public function restore(Course $course): JsonResponse
    {
        return ApiResponse::success(CoursePresenter::detail($this->courses->restore($course)));
    }

    /**
     * Contenido de una versión publicada, tal como quedó guardada.
     */
    public function version(Request $request, Course $course, string $version): JsonResponse
    {
        $this->access->assertCanView($request->user(), $course);

        $record = $course->versions()->with('publisher')->findOrFail($version);

        return ApiResponse::success(CoursePresenter::version($record) + ['snapshot' => $record->snapshot]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],
            'category' => ['nullable', 'string', 'max:80'],
            'level' => ['nullable', Rule::in(Course::LEVELS)],
            'duration_hours' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'access_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            // Vacío = no está en venta; 0 = gratuito
            'price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            // Quien completa el curso recibe certificado (RN-007)
            'issues_certificate' => ['nullable', 'boolean'],
            // Una sección se abre al completar la anterior
            'sequential' => ['nullable', 'boolean'],
            // Nota final mínima para aprobar, en porcentaje; vacío = no se exige
            'passing_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Asistencia mínima a las clases en vivo para el certificado, en porcentaje; vacío = no se exige
            'min_attendance_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'language' => ['required', Rule::in(Course::LANGUAGES)],
        ]);
    }
}
