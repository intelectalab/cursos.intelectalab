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
use Illuminate\Support\Facades\DB;

/**
 * Tareas (FP-08): instrucción → entrega → revisión del profesor →
 * nota y comentario. La tarea cuenta para el avance al entregarla; la nota
 * va aparte.
 */
class TaskSubmissionService
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly ProgressService $progress,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Entrega o reemplaza la entrega mientras no esté calificada.
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

        $submission = DB::transaction(function () use ($enrollment, $activity, $existing, $body, $upload) {
            $data = [
                'body' => $body,
                'file_id' => $upload ? $this->storage->store($upload, TaskSubmission::FILE_PURPOSE)->id : $existing?->file_id,
                'status' => TaskSubmission::STATUS_SUBMITTED,
                'submitted_at' => now(),
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
            'grade_percent' => $submission->grade_percent,
            'feedback' => $submission->feedback,
            'graded_at' => $submission->graded_at?->toIso8601String(),
        ];
    }
}
