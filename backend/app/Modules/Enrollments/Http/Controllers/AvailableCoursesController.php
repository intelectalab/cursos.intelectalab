<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Http\CatalogPresenter;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Cursos disponibles" del portal del alumno (RF-STU-001): los cursos
 * publicados en los que aún no está matriculado. Solo la ficha: el
 * contenido exige matrícula (RN-003). Los que tienen precio se pueden comprar.
 */
class AvailableCoursesController extends Controller
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        $currency = $this->settings->currency();
        $courses = $this->query($request->user())->orderBy('title')->get();

        return ApiResponse::success($courses->map(fn (Course $course) => CatalogPresenter::card($course, $currency))->values());
    }

    public function show(Request $request, string $course): JsonResponse
    {
        $record = $this->query($request->user())->whereKey($course)->first();

        if (! $record) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }

        return ApiResponse::success(CatalogPresenter::detail($record, $this->settings->currency()));
    }

    /**
     * Cursos publicados sin matrícula vigente del alumno (una cancelada no cuenta).
     *
     * @return Builder<Course>
     */
    private function query(User $student): Builder
    {
        return Course::query()
            ->where('status', Course::STATUS_PUBLISHED)
            ->whereNotNull('current_version_id')
            ->whereNotIn('id', Enrollment::query()
                ->where('student_id', $student->id)
                ->where('status', Enrollment::STATUS_ACTIVE)
                ->select('course_id'))
            ->with(['currentVersion', 'teachers']);
    }
}
