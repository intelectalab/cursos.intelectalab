<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Application\ScormLaunch;
use App\Modules\Academic\Application\ScormPackageService;
use App\Modules\Academic\Application\ScormTracker;
use App\Modules\Academic\Domain\Models\CourseActivity;
use App\Modules\Academic\Domain\Models\ScormPackage;
use App\Modules\Academic\Http\AcademicPresenter;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Application\SequenceGate;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Actividades SCORM: el personal sube el paquete y lo prueba; el alumno lo
 * abre en el aula y el paquete guarda su avance; el personal puede reiniciarlo.
 */
class ScormController extends Controller
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly ScormTracker $tracker,
    ) {}

    public function upload(Request $request, Course $course, string $activity, ScormPackageService $packages): JsonResponse
    {
        $this->access->assertCanManageActivities($request->user(), $course);
        $record = $this->draftActivity($course, $activity);

        $request->validate(
            ['file' => ['required', 'file', 'mimes:zip', 'max:'.ScormPackageService::MAX_KB]],
            ['file.max' => 'El paquete pesa más de 60 MB.', 'file.mimes' => 'Sube el paquete SCORM como archivo .zip.'],
        );

        $packages->upload($course, $record, $request->file('file'), $request->user());

        return ApiResponse::success(AcademicPresenter::activity($record->fresh(['scormPackage'])), null, 201);
    }

    /**
     * El personal prueba el paquete del borrador. No se guarda nada.
     */
    public function preview(Request $request, Course $course, string $activity): JsonResponse
    {
        $this->access->assertCanView($request->user(), $course);
        $package = $this->draftActivity($course, $activity)->scormPackage
            ?? throw new ApiException(ErrorCode::NotFound, 'Todavía no se subió el paquete.', 404);

        return ApiResponse::success(['launch_url' => ScormLaunch::url($package), 'version' => $package->version, 'cmi' => (object) [], 'tracking' => false]);
    }

    /**
     * El alumno abre el paquete publicado: dónde está y lo que lleva guardado.
     */
    public function launch(Request $request, string $course, string $activity, EnrollmentService $enrollments, SequenceGate $gate): JsonResponse
    {
        [$enrollment, $record, $package] = $this->published($request, $course, $activity, $enrollments, $gate);

        return ApiResponse::success([
            'launch_url' => ScormLaunch::url($package),
            'version' => $package->version,
            'cmi' => (object) $this->tracker->initial($enrollment, $record, $package),
            'tracking' => true,
            'state' => $this->tracker->state($enrollment, $record),
        ]);
    }

    /**
     * Lo que el paquete guardó (LMSCommit / Commit, y al cerrarse).
     */
    public function commit(Request $request, string $course, string $activity, EnrollmentService $enrollments, SequenceGate $gate): JsonResponse
    {
        $data = $request->validate(['cmi' => ['present', 'array', 'max:2000']]);
        [$enrollment, $record, $package] = $this->published($request, $course, $activity, $enrollments, $gate);

        $this->tracker->commit($enrollment, $record, $package, $data['cmi']);

        return ApiResponse::success($this->tracker->state($enrollment, $record));
    }

    /**
     * El personal reinicia el intento de un alumno: vuelve a empezar el paquete.
     */
    public function reset(Request $request, Course $course, string $enrollment, string $activity): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);
        $record = Enrollment::query()->where('course_id', $course->id)->findOrFail($enrollment);

        $this->tracker->reset($record, $activity);

        return ApiResponse::success(null);
    }

    private function draftActivity(Course $course, string $id): CourseActivity
    {
        return CourseActivity::query()
            ->where('course_id', $course->id)
            ->where('type', CourseActivity::TYPE_SCORM)
            ->with('scormPackage')
            ->findOrFail($id);
    }

    /**
     * Matrícula activa del alumno, la actividad publicada y su paquete.
     *
     * @return array{0: Enrollment, 1: array<string, mixed>, 2: ScormPackage}
     */
    private function published(Request $request, string $course, string $activity, EnrollmentService $enrollments, SequenceGate $gate): array
    {
        $enrollment = $enrollments->activeFor($request->user(), $course);
        $record = ClassroomPresenter::activityOf($enrollment->course->currentVersion, $activity);

        if (! $record || $record['type'] !== CourseActivity::TYPE_SCORM || empty($record['scorm']['id'])) {
            throw new ApiException(ErrorCode::NotFound, 'La actividad no existe.', 404);
        }

        // Con avance secuencial, la actividad de una sección cerrada no se abre
        $gate->assertOpen($enrollment, $record['id']);

        $package = ScormPackage::query()->findOrFail($record['scorm']['id']);
        $enrollment->loadMissing('student');

        return [$enrollment, $record, $package];
    }
}
