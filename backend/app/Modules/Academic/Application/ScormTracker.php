<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Events\GradeRecorded;
use App\Modules\Academic\Domain\Models\ScormAttempt;
use App\Modules\Academic\Domain\Models\ScormPackage;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el paquete SCORM informa del alumno (cmi.*): se guarda tal cual
 * para retomarlo donde quedó, y de ahí sale el estado, la nota y el avance.
 *
 * - SCORM 1.2: cmi.core.lesson_status y cmi.core.score.raw/min/max.
 * - SCORM 2004: cmi.completion_status, cmi.success_status y cmi.score.scaled o raw/min/max.
 */
class ScormTracker
{
    /** Datos que el paquete no puede escribir: los pone el sistema */
    private const READ_ONLY = ['cmi.core.student_id', 'cmi.core.student_name', 'cmi.learner_id', 'cmi.learner_name', 'cmi.core.credit', 'cmi.credit', 'cmi.mode', 'cmi.core.lesson_mode'];

    public function __construct(private readonly ProgressService $progress) {}

    public function find(Enrollment $enrollment, string $activityId): ?ScormAttempt
    {
        return ScormAttempt::query()->where('enrollment_id', $enrollment->id)->where('activity_id', $activityId)->first();
    }

    /**
     * Datos con los que arranca el paquete: lo guardado más los del alumno.
     *
     * @param  array<string, mixed>  $activity  actividad de la versión publicada
     * @return array<string, string>
     */
    public function initial(Enrollment $enrollment, array $activity, ScormPackage $package): array
    {
        $attempt = $this->find($enrollment, $activity['id']);
        $student = $enrollment->student;
        $name = trim("{$student->last_name}, {$student->first_name}", ', ');
        $saved = $attempt?->cmi ?? [];

        $base = $package->version === ScormPackage::VERSION_2004
            ? [
                'cmi.learner_id' => $student->id,
                'cmi.learner_name' => $name,
                'cmi.credit' => 'credit',
                'cmi.mode' => 'normal',
                'cmi.entry' => $saved === [] ? 'ab-initio' : 'resume',
                'cmi.completion_status' => 'unknown',
                'cmi.success_status' => 'unknown',
            ]
            : [
                'cmi.core.student_id' => $student->id,
                'cmi.core.student_name' => $name,
                'cmi.core.credit' => 'credit',
                'cmi.core.lesson_mode' => 'normal',
                'cmi.core.entry' => $saved === [] ? 'ab-initio' : 'resume',
                'cmi.core.lesson_status' => 'not attempted',
            ];

        if ($package->mastery_score !== null) {
            $base[$package->version === ScormPackage::VERSION_2004 ? 'cmi.scaled_passing_score' : 'cmi.student_data.mastery_score'] = $package->version === ScormPackage::VERSION_2004
                ? (string) round($package->mastery_score / 100, 4)
                : (string) $package->mastery_score;
        }

        // Lo guardado manda, salvo los datos del alumno
        return array_map('strval', array_merge($base, array_diff_key($saved, array_flip(self::READ_ONLY)), array_intersect_key($base, array_flip(self::READ_ONLY))));
    }

    /**
     * Guarda lo que mandó el paquete y recalcula estado, nota y avance.
     *
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $values  claves cmi.* que cambiaron
     */
    public function commit(Enrollment $enrollment, array $activity, ScormPackage $package, array $values): ScormAttempt
    {
        $values = collect($values)
            ->filter(fn ($value, $key) => is_string($key) && str_starts_with($key, 'cmi.') && strlen($key) <= 255 && ! in_array($key, self::READ_ONLY, true))
            ->map(fn ($value) => mb_substr((string) $value, 0, 64000))
            ->all();

        return DB::transaction(function () use ($enrollment, $activity, $package, $values) {
            $attempt = ScormAttempt::query()->lockForUpdate()->firstOrNew([
                'enrollment_id' => $enrollment->id,
                'activity_id' => $activity['id'],
            ], [
                'student_id' => $enrollment->student_id,
                'course_id' => $enrollment->course_id,
                'package_id' => $package->id,
                'cmi' => [],
            ]);

            $cmi = array_merge($attempt->cmi ?? [], $values);
            if (strlen((string) json_encode($cmi)) > 1_000_000) {
                throw new ApiException(ErrorCode::ValidationError, 'El paquete intentó guardar demasiados datos.', 422);
            }

            $before = [$attempt->lesson_status, $attempt->score_percent];
            [$status, $score] = $this->evaluate($package, $cmi);

            $attempt->fill([
                'cmi' => $cmi,
                'lesson_status' => $status,
                'score_percent' => $score,
                'last_seen_at' => now(),
            ]);

            $done = in_array($status, ['completed', 'passed'], true);
            if ($done && $attempt->completed_at === null) {
                $attempt->completed_at = now();
            }
            $attempt->save();

            if ($done) {
                $this->progress->complete($enrollment, EnrollmentProgress::TYPE_ACTIVITY, $activity['id']);
            }

            // La nota cambió: la nota final y el certificado se recalculan
            if ($before !== [$status, $score]) {
                GradeRecorded::dispatch($enrollment->id);
            }

            return $attempt;
        });
    }

    /**
     * El personal reinicia el intento: el alumno vuelve a empezar el paquete.
     */
    public function reset(Enrollment $enrollment, string $activityId): void
    {
        $this->find($enrollment, $activityId)?->delete();
        GradeRecorded::dispatch($enrollment->id);
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    public function state(Enrollment $enrollment, array $activity): array
    {
        $attempt = $this->find($enrollment, $activity['id']);

        return [
            'lesson_status' => $attempt?->lesson_status ?? 'not attempted',
            'score_percent' => $attempt?->score_percent,
            'completed' => $attempt?->completed_at !== null,
            'last_seen_at' => $attempt?->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * Estado normalizado (como en 1.2) y nota en porcentaje.
     *
     * @param  array<string, string>  $cmi
     * @return array{0: string, 1: float|null}
     */
    private function evaluate(ScormPackage $package, array $cmi): array
    {
        $prefix = $package->version === ScormPackage::VERSION_2004 ? 'cmi.score.' : 'cmi.core.score.';
        $score = null;

        if ($package->version === ScormPackage::VERSION_2004 && is_numeric($cmi['cmi.score.scaled'] ?? null)) {
            $score = (float) $cmi['cmi.score.scaled'] * 100;
        } elseif (is_numeric($cmi[$prefix.'raw'] ?? null)) {
            $min = is_numeric($cmi[$prefix.'min'] ?? null) ? (float) $cmi[$prefix.'min'] : 0.0;
            $max = is_numeric($cmi[$prefix.'max'] ?? null) ? (float) $cmi[$prefix.'max'] : 100.0;
            $score = $max > $min ? ((float) $cmi[$prefix.'raw'] - $min) * 100 / ($max - $min) : null;
        }
        $score = $score === null ? null : round(max(0.0, min(100.0, $score)), 2);

        if ($package->version === ScormPackage::VERSION_2004) {
            $success = $cmi['cmi.success_status'] ?? 'unknown';
            $completion = $cmi['cmi.completion_status'] ?? 'unknown';
            $status = match (true) {
                $success === 'passed' => 'passed',
                $success === 'failed' => 'failed',
                $completion === 'completed' => 'completed',
                $completion === 'incomplete' => 'incomplete',
                default => 'not attempted',
            };
        } else {
            $status = $cmi['cmi.core.lesson_status'] ?? 'not attempted';
            $status = in_array($status, ['passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted'], true) ? $status : 'incomplete';
        }

        // Con nota mínima en el paquete y nota informada, el sistema decide aprobado o no (como pide SCORM 1.2)
        if ($package->mastery_score !== null && $score !== null && in_array($status, ['completed', 'incomplete'], true) && $package->version === ScormPackage::VERSION_12) {
            $status = $score >= $package->mastery_score ? 'passed' : 'failed';
        }

        return [$status, $score];
    }
}
