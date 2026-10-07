<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Application\QuizAttemptService;
use App\Modules\Academic\Application\SurveyResponseService;
use App\Modules\Academic\Application\TaskSubmissionService;
use App\Modules\Academic\Domain\Models\QuizAttempt;
use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Modules\Files\Application\FileStorage;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lo que el alumno hace con las actividades de sus cursos. Todo parte de su
 * matrícula activa y de la versión publicada vigente.
 */
class StudentActivityController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly QuizAttemptService $quizzes,
        private readonly TaskSubmissionService $tasks,
        private readonly SurveyResponseService $surveys,
    ) {}

    /**
     * Situación del alumno en cada actividad del curso.
     */
    public function index(Request $request, string $course): JsonResponse
    {
        $enrollment = $this->enrollments->activeFor($request->user(), $course);

        $states = [];

        foreach (ClassroomPresenter::activitiesOf($enrollment->course->currentVersion) as $activity) {
            $states[$activity['id']] = $this->state($enrollment, $activity);
        }

        return ApiResponse::success((object) $states);
    }

    /**
     * Contenido de una actividad para rendirla: la consigna de la tarea o
     * las preguntas de la encuesta. Las del cuestionario llegan al abrir un intento.
     */
    public function show(Request $request, string $course, string $activity): JsonResponse
    {
        [$enrollment, $record] = $this->resolve($request, $course, $activity);

        return ApiResponse::success(['state' => $this->state($enrollment, $record)] + match ($record['type']) {
            'SURVEY' => ['questions' => $record['survey']['questions']],
            default => [],
        });
    }

    public function startAttempt(Request $request, string $course, string $activity): JsonResponse
    {
        [$enrollment, $record] = $this->resolve($request, $course, $activity, 'QUIZ');

        return ApiResponse::success($this->quizzes->presentOpen($this->quizzes->start($enrollment, $record)), null, 201);
    }

    public function submitAttempt(Request $request, string $attempt): JsonResponse
    {
        $data = $request->validate(['answers' => ['present', 'array', 'max:300']]);
        [$record, $enrollment, $activity] = $this->attempt($request, $attempt);

        $record = $this->quizzes->submit($record, $enrollment, $data['answers']);

        return ApiResponse::success($this->quizzes->presentResult($record->load('answers'), $this->reveals($enrollment, $activity, $record)));
    }

    /**
     * Guardado automático de lo que lleva marcado en un intento en curso.
     */
    public function saveAttemptDraft(Request $request, string $attempt): JsonResponse
    {
        $data = $request->validate(['answers' => ['present', 'array', 'max:300']]);
        [$record] = $this->attempt($request, $attempt);

        $this->quizzes->saveDraft($record, $data['answers']);

        return ApiResponse::success(null);
    }

    /**
     * Un intento propio: en curso (para retomarlo) o su resultado.
     */
    public function showAttempt(Request $request, string $attempt): JsonResponse
    {
        [$record, $enrollment, $activity] = $this->attempt($request, $attempt);

        // Refresca el estado: un intento con el tiempo vencido se cierra al consultarlo
        $this->quizzes->state($enrollment, $activity ?? ['id' => $record->activity_id, 'quiz' => ['max_attempts' => null]]);
        $record->refresh();

        return ApiResponse::success(
            $record->isOpen()
                ? $this->quizzes->presentOpen($record)
                : $this->quizzes->presentResult($record->load('answers'), $this->reveals($enrollment, $activity, $record))
        );
    }

    public function submitTask(Request $request, string $course, string $activity, FileStorage $storage): JsonResponse
    {
        [$enrollment, $record] = $this->resolve($request, $course, $activity, 'TASK');

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:50000'],
            'file' => ['nullable', ...array_slice($storage->rules(TaskSubmission::FILE_PURPOSE), 1)],
        ]);

        $submission = $this->tasks->submit($enrollment, $record, $data['body'] ?? null, $request->file('file'));

        return ApiResponse::success($this->tasks->present($submission->load('file')));
    }

    public function downloadSubmission(Request $request, string $course, string $activity, FileStorage $storage): StreamedResponse
    {
        [$enrollment, $record] = $this->resolve($request, $course, $activity, 'TASK');
        $file = $this->tasks->find($enrollment, $record['id'])?->file;

        if (! $file || ! $storage->exists($file)) {
            throw new ApiException(ErrorCode::NotFound, 'La entrega no tiene un archivo.', 404);
        }

        return $storage->response($file, download: true);
    }

    public function answerSurvey(Request $request, string $course, string $activity): JsonResponse
    {
        [$enrollment, $record] = $this->resolve($request, $course, $activity, 'SURVEY');
        $data = $request->validate(['answers' => ['present', 'array', 'max:100']]);

        $this->surveys->answer($enrollment, $record, $data['answers']);

        return ApiResponse::success($this->state($enrollment, $record), null, 201);
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private function state(Enrollment $enrollment, array $activity): array
    {
        return match ($activity['type']) {
            'QUIZ' => $this->quizzes->state($enrollment, $activity),
            'TASK' => ['submission' => $this->tasks->present($this->tasks->find($enrollment, $activity['id']))],
            'SURVEY' => ['answered' => $this->surveys->hasAnswered($enrollment, $activity['id'])],
            default => [],
        };
    }

    /**
     * Matrícula activa del alumno y la actividad dentro de la versión publicada.
     *
     * @return array{0: Enrollment, 1: array<string, mixed>}
     */
    private function resolve(Request $request, string $course, string $activity, ?string $type = null): array
    {
        $enrollment = $this->enrollments->activeFor($request->user(), $course);
        $record = ClassroomPresenter::activityOf($enrollment->course->currentVersion, $activity);

        if (! $record || ($type !== null && $record['type'] !== $type)) {
            throw new ApiException(ErrorCode::NotFound, 'La actividad no existe.', 404);
        }

        return [$enrollment, $record];
    }

    /**
     * Intento del propio alumno, con su matrícula activa. El de otra persona no existe.
     *
     * @return array{0: QuizAttempt, 1: Enrollment, 2: array<string, mixed>|null}
     */
    private function attempt(Request $request, string $id): array
    {
        $attempt = QuizAttempt::query()->whereKey($id)->where('student_id', $request->user()->id)->first();

        if (! $attempt) {
            throw new ApiException(ErrorCode::NotFound, 'El intento no existe.', 404);
        }

        $enrollment = $this->enrollments->activeFor($request->user(), $attempt->course_id);

        return [$attempt, $enrollment, ClassroomPresenter::activityOf($enrollment->course->currentVersion, $attempt->activity_id)];
    }

    /**
     * Las respuestas correctas se muestran cuando ya aprobó o no le quedan intentos.
     *
     * @param  array<string, mixed>|null  $activity
     */
    private function reveals(Enrollment $enrollment, ?array $activity, QuizAttempt $attempt): bool
    {
        if ($activity === null) {
            return true;
        }

        $state = $this->quizzes->stateFrom($this->quizzes->attempts($enrollment, $attempt->activity_id), $activity);

        return $state['passed'] || $state['attempts_left'] === 0;
    }
}
