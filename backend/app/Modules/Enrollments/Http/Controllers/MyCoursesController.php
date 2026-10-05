<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
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
    public function __construct(private readonly EnrollmentService $enrollments) {}

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
            $enrollments->map(fn (Enrollment $enrollment) => ClassroomPresenter::summary($enrollment))->values()
        );
    }

    public function show(Request $request, string $course, TenantSettingsService $settings): JsonResponse
    {
        return ApiResponse::success(
            ClassroomPresenter::classroom($this->enrollments->activeFor($request->user(), $course)),
            // Escala en la que se muestran las notas de la organización
            ['grade_scale' => $settings->gradeScale()]
        );
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
