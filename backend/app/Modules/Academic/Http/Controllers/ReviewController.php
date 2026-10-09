<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Application\GradebookService;
use App\Modules\Academic\Application\QuizAttemptService;
use App\Modules\Academic\Application\SurveyResponseService;
use App\Modules\Academic\Application\TaskSubmissionService;
use App\Modules\Academic\Domain\Models\QuizAttempt;
use App\Modules\Academic\Domain\Models\QuizAttemptGrant;
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
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
    public function tracking(Request $request, Course $course, ProgressService $progress, TenantSettingsService $settings, GradebookService $gradebook): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $version = $course->currentVersion;
        $activities = $version ? ClassroomPresenter::activitiesOf($version) : [];
        $enrollments = $this->activeEnrollments($course);

        $summaries = $version ? $progress->summaries($version, $enrollments) : [];
        $rows = $gradebook->rows($course->id, $activities, $enrollments);

        return ApiResponse::success([
            'activities' => array_map(fn (array $activity) => [
                'id' => $activity['id'],
                'type' => $activity['type'],
                'title' => $activity['title'],
                'section' => $activity['section'],
                // Peso en la nota final; las encuestas no llevan nota
                'weight' => $activity['type'] === 'SURVEY' ? null : GradebookService::weight($activity),
            ], $activities),
            'students' => $enrollments->map(fn (Enrollment $enrollment) => [
                'enrollment_id' => $enrollment->id,
                'name' => trim("{$enrollment->student->first_name} {$enrollment->student->last_name}"),
                'email' => $enrollment->student->email,
                'progress' => array_intersect_key(
                    $summaries[$enrollment->id] ?? ['percent' => 0, 'completed' => 0, 'total' => 0],
                    array_flip(['percent', 'completed', 'total'])
                ),
                'final_percent' => $rows[$enrollment->id]['final_percent'],
                'passed' => GradebookService::passed($rows[$enrollment->id]['final_percent'], $course->passing_percent),
                // Por actividad: mejor nota del cuestionario, estado y nota de la tarea, o si respondió la encuesta
                'grades' => collect($activities)->mapWithKeys(fn (array $activity) => [
                    $activity['id'] => $rows[$enrollment->id]['grades'][$activity['id']]
                        ?? ['done' => in_array($activity['id'], $summaries[$enrollment->id]['activity_ids'] ?? [], true)],
                ])->all(),
            ])->values(),
        ], [
            'grade_scale' => $settings->gradeScale(),
            // Nota mínima para aprobar el curso; null = no la exige
            'passing_percent' => $course->passing_percent,
            'pending_submissions' => TaskSubmission::query()
                ->where('course_id', $course->id)
                ->where('status', TaskSubmission::STATUS_SUBMITTED)
                ->count(),
        ]);
    }

    /**
     * Libro de notas en Excel: una fila por alumno, una columna por actividad con nota.
     */
    public function exportGradebook(Request $request, Course $course, GradebookService $gradebook, TenantSettingsService $settings): BinaryFileResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $version = $course->currentVersion;
        $path = $gradebook->export(
            $course->id,
            $version ? ClassroomPresenter::activitiesOf($version) : [],
            $this->activeEnrollments($course),
            $settings->gradeScale()
        );

        return response()->download($path, 'notas.xlsx')->deleteFileAfterSend();
    }

    public function submissions(Request $request, Course $course): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(TaskSubmission::STATUSES)],
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

    public function returnSubmission(Request $request, Course $course, string $submission): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $data = $request->validate(['feedback' => ['required', 'string', 'max:5000']]);

        $record = $this->tasks->returnToStudent($this->submission($course, $submission), $data['feedback'], $request->user());

        return ApiResponse::success($this->tasks->present($record));
    }

    /**
     * Todos los intentos de un alumno en un cuestionario, con sus respuestas
     * y las correctas, y los intentos adicionales que se le concedieron.
     */
    public function attempts(Request $request, Course $course, string $enrollment, string $activity, QuizAttemptService $quizzes): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $record = $this->enrollment($course, $enrollment);
        $quiz = $course->currentVersion ? ClassroomPresenter::activityOf($course->currentVersion, $activity) : null;

        if ($quiz !== null && $quiz['type'] !== 'QUIZ') {
            throw new ApiException(ErrorCode::NotFound, 'El cuestionario no existe.', 404);
        }

        // Cierra antes los intentos con el tiempo vencido, como al consultarlos el alumno
        $state = $quiz ? $quizzes->state($record, $quiz) : null;

        $attempts = QuizAttempt::query()
            ->where('enrollment_id', $record->id)
            ->where('activity_id', $activity)
            ->where('status', '!=', QuizAttempt::STATUS_IN_PROGRESS)
            ->with('answers')
            ->orderBy('number')
            ->get();

        if ($quiz === null && $attempts->isEmpty()) {
            throw new ApiException(ErrorCode::NotFound, 'El cuestionario no existe.', 404);
        }

        return ApiResponse::success([
            'student' => [
                'name' => trim("{$record->student->first_name} {$record->student->last_name}"),
                'email' => $record->student->email,
            ],
            'title' => $quiz['title'] ?? 'Cuestionario retirado del curso',
            'max_attempts' => $quiz['quiz']['max_attempts'] ?? null,
            'extra_attempts' => $state['extra_attempts'] ?? 0,
            'attempts_left' => $state['attempts_left'] ?? null,
            'in_progress' => ($state['open_attempt_id'] ?? null) !== null,
            'can_grant' => $quiz !== null && $quiz['quiz']['max_attempts'] !== null,
            'attempts' => $attempts->map(fn (QuizAttempt $attempt) => $quizzes->presentResult($attempt, true))->values(),
            'grants' => QuizAttemptGrant::query()
                ->where('enrollment_id', $record->id)
                ->where('activity_id', $activity)
                ->with('grantedBy')
                ->orderBy('created_at')
                ->get()
                ->map(fn (QuizAttemptGrant $grant) => [
                    'attempts' => $grant->attempts,
                    'reason' => $grant->reason,
                    'granted_by' => $grant->grantedBy ? trim("{$grant->grantedBy->first_name} {$grant->grantedBy->last_name}") : null,
                    'created_at' => $grant->created_at->toIso8601String(),
                ])->values(),
        ]);
    }

    public function grantAttempts(Request $request, Course $course, string $enrollment, string $activity, QuizAttemptService $quizzes): JsonResponse
    {
        $this->access->assertCanReview($request->user(), $course);

        $data = $request->validate([
            'attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        $record = $this->enrollment($course, $enrollment);
        $quiz = $course->currentVersion ? ClassroomPresenter::activityOf($course->currentVersion, $activity) : null;

        if (! $quiz || $quiz['type'] !== 'QUIZ') {
            throw new ApiException(ErrorCode::NotFound, 'El cuestionario no existe en la versión publicada.', 404);
        }

        $quizzes->grant($record, $quiz, (int) $data['attempts'], $data['reason'], $request->user());

        return ApiResponse::success([
            'extra_attempts' => $quizzes->extraAttempts($record, $activity),
        ], null, 201);
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

    /**
     * Matrículas activas del curso, ordenadas por nombre del alumno.
     *
     * @return Collection<int, Enrollment>
     */
    private function activeEnrollments(Course $course): Collection
    {
        return Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->with('student')
            ->get()
            ->sortBy(fn (Enrollment $enrollment) => mb_strtolower("{$enrollment->student->first_name} {$enrollment->student->last_name}"))
            ->values();
    }

    private function enrollment(Course $course, string $id): Enrollment
    {
        return Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->with('student')
            ->findOrFail($id);
    }

    private function submission(Course $course, string $id): TaskSubmission
    {
        return TaskSubmission::query()->where('course_id', $course->id)->with('file')->findOrFail($id);
    }
}
