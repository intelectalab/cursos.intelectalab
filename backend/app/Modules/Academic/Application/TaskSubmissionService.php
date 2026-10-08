<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tareas (FP-08): instrucción → entrega → revisión del profesor →
 * nota y comentario. La tarea cuenta para el avance al entregarla; la nota
 * va aparte. Si tiene plazo, la entrega fuera de él queda marcada como
 * tardía o se rechaza, según la tarea (YC-EVA-006). Quien revisa puede
 * devolverla para que el alumno la rehaga (YC-EVA-007).
 */
class TaskSubmissionService
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly ProgressService $progress,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Entrega o reemplaza la entrega mientras no esté calificada. Una entrega
     * devuelta se rehace aunque el plazo haya pasado y conserva su marca.
     *
     * @param  array<string, mixed>  $activity  tarea de la versión publicada
     */
    public function submit(Enrollment $enrollment, array $activity, ?string $body, ?UploadedFile $upload): TaskSubmission
    {
        $task = $activity['task'];
        $body = $task['allow_text'] ? (trim((string) $body) ?: null) : null;
        $upload = $task['allow_file'] ? $upload : null;

        $existing = $this->find($enrollment, $activity['id']);

        if ($existing?->isGraded()) {
            throw new ApiException(ErrorCode::SubmissionGraded, 'La tarea ya fue calificada y no se puede volver a entregar.', 409);
        }

        $late = $this->isPastDue($enrollment, $activity);

        if ($late && ! $existing?->isReturned() && ! ($task['accept_late'] ?? true)) {
            throw new ApiException(ErrorCode::SubmissionLate, 'El plazo de esta tarea ya venció y no admite entregas tardías.', 409);
        }

        // Al reemplazar se puede conservar el archivo ya entregado
        if ($body === null && $upload === null && ! $existing?->file_id) {
            throw new ApiException(
                ErrorCode::ValidationError,
                'La entrega está vacía.',
                422,
                ['submission' => [$task['allow_file'] && ! $task['allow_text'] ? 'Adjunta un archivo.' : 'Escribe tu respuesta o adjunta un archivo.']]
            );
        }

        $previousFile = $upload ? $existing?->file : null;

        $submission = DB::transaction(function () use ($enrollment, $activity, $existing, $body, $upload, $late) {
            $data = [
                'body' => $body,
                'file_id' => $upload ? $this->storage->store($upload, TaskSubmission::FILE_PURPOSE)->id : $existing?->file_id,
                'status' => TaskSubmission::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'is_late' => $existing?->isReturned() ? $existing->is_late : $late,
            ];

            $submission = $existing
                ? tap($existing)->update($data)
                : TaskSubmission::create($data + [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'course_id' => $enrollment->course_id,
                    'activity_id' => $activity['id'],
                ]);

            $this->progress->complete($enrollment, EnrollmentProgress::TYPE_ACTIVITY, $activity['id']);

            return $submission;
        });

        if ($previousFile) {
            $this->storage->delete($previousFile);
        }

        return $submission;
    }

    public function grade(TaskSubmission $submission, float $percent, ?string $feedback, User $reviewer): TaskSubmission
    {
        if ($submission->isReturned()) {
            throw new ApiException(ErrorCode::ValidationError, 'La entrega fue devuelta: espera a que el alumno la vuelva a entregar.', 422);
        }

        return DB::transaction(function () use ($submission, $percent, $feedback, $reviewer) {
            $before = $submission->only(['status', 'grade_percent']);

            $submission->update([
                'status' => TaskSubmission::STATUS_GRADED,
                'grade_percent' => $percent,
                'feedback' => $feedback,
                'graded_by' => $reviewer->id,
                'graded_at' => now(),
            ]);

            $this->audit->record('submissions.graded', $submission, $before, $submission->only(['status', 'grade_percent']));

            return $submission;
        });
    }

    /**
     * Devuelve la entrega al alumno para que la rehaga: pierde la nota y
     * queda abierta a una nueva entrega, con el comentario de quien revisa.
     */
    public function returnToStudent(TaskSubmission $submission, string $feedback, User $reviewer): TaskSubmission
    {
        if ($submission->isReturned()) {
            throw new ApiException(ErrorCode::ValidationError, 'Esta entrega ya fue devuelta.', 422);
        }

        return DB::transaction(function () use ($submission, $feedback, $reviewer) {
            $before = $submission->only(['status', 'grade_percent']);

            $submission->update([
                'status' => TaskSubmission::STATUS_RETURNED,
                'grade_percent' => null,
                'graded_by' => null,
                'graded_at' => null,
                'feedback' => $feedback,
                'returned_at' => now(),
                'returned_by' => $reviewer->id,
            ]);

            $this->audit->record('submissions.returned', $submission, $before, $submission->only(['status', 'grade_percent']));

            return $submission;
        });
    }

    /**
     * Fecha límite de la tarea para esta matrícula: los días de plazo se
     * cuentan desde que empieza su acceso al curso.
     *
     * @param  array<string, mixed>  $activity
     */
    public function dueAt(Enrollment $enrollment, array $activity): ?Carbon
    {
        $days = $activity['task']['due_days'] ?? null;

        if ($days === null) {
            return null;
        }

        return ($enrollment->starts_at ?? $enrollment->enrolled_at)->copy()->addDays((int) $days);
    }

    /**
     * Situación del alumno en una tarea.
     *
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    public function state(Enrollment $enrollment, array $activity): array
    {
        $submission = $this->find($enrollment, $activity['id']);
        $late = $this->isPastDue($enrollment, $activity);

        return [
            'submission' => $this->present($submission),
            'due_at' => $this->dueAt($enrollment, $activity)?->toIso8601String(),
            'past_due' => $late,
            'can_submit' => ! $submission?->isGraded()
                && (! $late || $submission?->isReturned() || ($activity['task']['accept_late'] ?? true)),
        ];
    }

    public function find(Enrollment $enrollment, string $activityId): ?TaskSubmission
    {
        return TaskSubmission::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('activity_id', $activityId)
            ->with('file')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function present(?TaskSubmission $submission): ?array
    {
        if (! $submission) {
            return null;
        }

        return [
            'id' => $submission->id,
            'status' => $submission->status,
            'body' => $submission->body,
            'file' => $submission->file ? [
                'name' => $submission->file->original_name,
                'size_bytes' => $submission->file->size_bytes,
            ] : null,
            'submitted_at' => $submission->submitted_at->toIso8601String(),
            'is_late' => $submission->is_late,
            'returned_at' => $submission->returned_at?->toIso8601String(),
            'grade_percent' => $submission->grade_percent,
            'feedback' => $submission->feedback,
            'graded_at' => $submission->graded_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function isPastDue(Enrollment $enrollment, array $activity): bool
    {
        return $this->dueAt($enrollment, $activity)?->isPast() ?? false;
    }
}
