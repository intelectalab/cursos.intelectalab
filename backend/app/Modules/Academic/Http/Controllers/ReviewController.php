<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Application\SurveyResponseService;
use App\Modules\Academic\Application\TaskSubmissionService;
use App\Modules\Academic\Domain\Models\QuizAttempt;
use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seguimiento de los alumnos de un curso: avance, notas, revisión de tareas
 * y resultados de encuestas. Para quien revisa todos los cursos o, el
 * profesor, los suyos.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly TaskSubmissionService $tasks,
    ) {}

    /**
     * Avance y notas de cada alumno con matrícula activa.
     */
    public function tracking(Request $request, Course $course, ProgressService $progress, TenantSettingsService $settings): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $version = $course->currentVersion;
        $activities = $version ? ClassroomPresenter::activitiesOf($version) : [];
        $enrollments = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->with('student')
            ->get()
            ->sortBy(fn (Enrollment $enrollment) => mb_strtolower("{$enrollment->student->first_name} {$enrollment->student->last_name}"))
            ->values();

        $summaries = $version ? $progress->summaries($version, $enrollments) : [];
        $best = QuizAttempt::query()
            ->where('course_id', $course->id)
            ->where('status', '!=', QuizAttempt::STATUS_IN_PROGRESS)
            ->get(['enrollment_id', 'activity_id', 'score_percent'])
            ->groupBy(fn (QuizAttempt $attempt) => "{$attempt->enrollment_id}:{$attempt->activity_id}")
            ->map(fn ($attempts) => $attempts->max('score_percent'));
        $submissions = TaskSubmission::query()
            ->where('course_id', $course->id)
            ->get(['enrollment_id', 'activity_id', 'status', 'grade_percent'])
            ->keyBy(fn (TaskSubmission $submission) => "{$submission->enrollment_id}:{$submission->activity_id}");

        return ApiResponse::success([
            'activities' => array_map(fn (array $activity) => [
                'id' => $activity['id'],
                'type' => $activity['type'],
                'title' => $activity['title'],
                'section' => $activity['section'],
            ], $activities),
            'students' => $enrollments->map(fn (Enrollment $enrollment) => [
                'enrollment_id' => $enrollment->id,
                'name' => trim("{$enrollment->student->first_name} {$enrollment->student->last_name}"),
                'email' => $enrollment->student->email,
                'progress' => array_intersect_key(
                    $summaries[$enrollment->id] ?? ['percent' => 0, 'completed' => 0, 'total' => 0],
                    array_flip(['percent', 'completed', 'total'])
                ),
                // Por actividad: mejor nota del cuestionario, o estado y nota de la tarea
                'grades' => collect($activities)->mapWithKeys(function (array $activity) use ($enrollment, $best, $submissions, $summaries) {
                    $key = "{$enrollment->id}:{$activity['id']}";

                    return [$activity['id'] => match ($activity['type']) {
                        'QUIZ' => ['grade_percent' => $best->get($key)],
                        'TASK' => [
                            'status' => $submissions->get($key)?->status,
                            'grade_percent' => $submissions->get($key)?->grade_percent,
                        ],
                        default => ['done' => in_array($activity['id'], $summaries[$enrollment->id]['activity_ids'] ?? [], true)],
                    }];
                })->all(),
            ])->values(),
        ], [
            'grade_scale' => $settings->gradeScale(),
            'pending_submissions' => $submissions->where('status', TaskSubmission::STATUS_SUBMITTED)->count(),
        ]);
    }

    public function submissions(Request $request, Course $course): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([TaskSubmission::STATUS_SUBMITTED, TaskSubmission::STATUS_GRADED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $titles = $course->currentVersion
            ? collect(ClassroomPresenter::activitiesOf($course->currentVersion))->pluck('title', 'id')
            : collect();

        $submissions = TaskSubmission::query()
            ->where('course_id', $course->id)
            ->with(['student', 'file'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            // Primero lo que falta revisar, de la entrega más antigua a la más nueva
            ->orderByRaw("status = 'SUBMITTED' desc")
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($submissions, fn (TaskSubmission $submission) => $this->tasks->present($submission) + [
            'activity_id' => $submission->activity_id,
            'activity_title' => $titles->get($submission->activity_id, 'Tarea retirada del curso'),
            'student' => [
                'name' => trim("{$submission->student->first_name} {$submission->student->last_name}"),
                'email' => $submission->student->email,
            ],
        ]);
    }

    public function grade(Request $request, Course $course, string $submission): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $data = $request->validate([
            'grade_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $record = $this->tasks->grade(
            $this->submission($course, $submission),
            (float) $data['grade_percent'],
            $data['feedback'] ?? null,
            $request->user()
        );

        return ApiResponse::success($this->tasks->present($record));
    }

    public function download(Request $request, Course $course, string $submission, FileStorage $storage): StreamedResponse
    {
        $this->access->assertCanReview($request->user(), $course);
        $file = $this->submission($course, $submission)->file;

        if (! $file || ! $storage->exists($file)) {
            throw new ApiException(ErrorCode::NotFound, 'La entrega no tiene un archivo.', 404);
        }

        return $storage->response($file, download: true);
    }

    public function surveyResults(Request $request, Course $course, string $activity, SurveyResponseService $surveys): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $record = $course->currentVersion ? ClassroomPresenter::activityOf($course->currentVersion, $activity) : null;

        if (! $record || $record['type'] !== 'SURVEY') {
            throw new ApiException(ErrorCode::NotFound, 'La encuesta no existe en la versión publicada.', 404);
        }

        return ApiResponse::success(['title' => $record['title']] + $surveys->results($course->id, $record));
    }

    private function submission(Course $course, string $id): TaskSubmission
    {
        return TaskSubmission::query()->where('course_id', $course->id)->with('file')->findOrFail($id);
    }
}
