<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal del alumno (RF-STU-001): sus cursos y el aula de cada uno. Todo
 * parte de su propia matrícula activa; un curso sin ella no existe para él.
 */
class MyCoursesController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly ProgressService $progress,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $enrollments = Enrollment::query()
            ->where('student_id', $request->user()->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->whereHas('course', fn ($course) => $course->whereNotNull('current_version_id'))
            ->with('course.currentVersion')
            ->orderByDesc('enrolled_at')
            ->get();

        return ApiResponse::success(
            $enrollments->map(fn (Enrollment $enrollment) => ClassroomPresenter::summary($enrollment) + [
                'progress_percent' => $this->progress->summary($enrollment)['percent'],
            ])->values()
        );
    }

    public function show(Request $request, string $course, TenantSettingsService $settings): JsonResponse
    {
        $enrollment = $this->enrollments->activeFor($request->user(), $course);

        return ApiResponse::success(
            ClassroomPresenter::classroom($enrollment) + ['progress' => $this->progress->summary($enrollment)],
            // Escala en la que se muestran las notas de la organización
            ['grade_scale' => $settings->gradeScale()]
        );
    }

    /**
     * Un recurso cuenta como completado cuando el alumno lo abre.
     */
    public function completeResource(Request $request, string $course, string $resource): JsonResponse
    {
        $enrollment = $this->enrollments->activeFor($request->user(), $course);

        if (! in_array($resource, $this->progress->items($enrollment->course->currentVersion)[EnrollmentProgress::TYPE_RESOURCE], true)) {
            throw new ApiException(ErrorCode::NotFound, 'El recurso no existe.', 404);
        }

        $this->progress->complete($enrollment, EnrollmentProgress::TYPE_RESOURCE, $resource);

        return ApiResponse::success($this->progress->summary($enrollment));
    }

    /**
     * Material de la versión vigente. Un archivo que solo existe en el borrador no se entrega.
     */
    public function download(Request $request, string $course, string $resource, FileStorage $storage): StreamedResponse
    {
        $enrollment = $this->enrollments->activeFor($request->user(), $course);
        $reference = ClassroomPresenter::fileOf($enrollment->course->currentVersion, $resource);
        $file = $reference ? File::query()->find($reference['id']) : null;

        if (! $file || ! $storage->exists($file)) {
            throw new ApiException(ErrorCode::NotFound, 'El recurso no tiene un archivo.', 404);
        }

        return $storage->response($file, download: true);
    }
}
