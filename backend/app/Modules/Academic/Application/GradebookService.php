<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\QuizAttempt;
use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Shared\Import\Spreadsheet;
use Illuminate\Support\Collection;

/**
 * Libro de notas (YC-EVA-009): la nota de cada cuestionario y tarea por
 * alumno y la nota final del curso. La final es el promedio ponderado por el
 * peso de cada actividad; lo que aún no tiene nota cuenta como cero. Las
 * encuestas no llevan nota.
 */
class GradebookService
{
    private const GRADED_TYPES = ['QUIZ', 'TASK'];

    /**
     * Actividades con nota de la versión publicada, con su peso.
     *
     * @param  list<array<string, mixed>>  $activities  actividades de la versión, en orden
     * @return list<array<string, mixed>>
     */
    public function gradedActivities(array $activities): array
    {
        return array_values(array_filter($activities, fn (array $activity) => in_array($activity['type'], self::GRADED_TYPES, true)));
    }

    /**
     * Nota por actividad y nota final de cada matrícula.
     *
     * @param  list<array<string, mixed>>  $activities  actividades de la versión, en orden
     * @param  Collection<int, Enrollment>  $enrollments  todas del mismo curso
     * @return array<string, array{grades: array<string, array<string, mixed>>, final_percent: float|null}>
     */
    public function rows(string $courseId, array $activities, Collection $enrollments): array
    {
        $ids = $enrollments->pluck('id');

        // En los cuestionarios cuenta el mejor intento cerrado
        $best = QuizAttempt::query()
            ->where('course_id', $courseId)
            ->whereIn('enrollment_id', $ids)
            ->where('status', '!=', QuizAttempt::STATUS_IN_PROGRESS)
            ->get(['enrollment_id', 'activity_id', 'score_percent'])
            ->groupBy(fn (QuizAttempt $attempt) => "{$attempt->enrollment_id}:{$attempt->activity_id}")
            ->map(fn ($attempts) => $attempts->max('score_percent'));
        $submissions = TaskSubmission::query()
            ->where('course_id', $courseId)
            ->whereIn('enrollment_id', $ids)
            ->get(['enrollment_id', 'activity_id', 'status', 'grade_percent', 'is_late'])
            ->keyBy(fn (TaskSubmission $submission) => "{$submission->enrollment_id}:{$submission->activity_id}");

        $graded = $this->gradedActivities($activities);
        $rows = [];

        foreach ($enrollments as $enrollment) {
            $grades = [];

            foreach ($graded as $activity) {
                $key = "{$enrollment->id}:{$activity['id']}";
                $grades[$activity['id']] = $activity['type'] === 'QUIZ'
                    ? ['grade_percent' => $best->get($key)]
                    : [
                        'status' => $submissions->get($key)?->status,
                        'grade_percent' => $submissions->get($key)?->grade_percent,
                        'is_late' => (bool) $submissions->get($key)?->is_late,
                    ];
            }

            $rows[$enrollment->id] = ['grades' => $grades, 'final_percent' => $this->finalPercent($graded, $grades)];
        }

        return $rows;
    }

    /**
     * Promedio ponderado; null si ninguna actividad cuenta para la nota.
     *
     * @param  list<array<string, mixed>>  $graded
     * @param  array<string, array<string, mixed>>  $grades
     */
    public function finalPercent(array $graded, array $grades): ?float
    {
        $earned = 0.0;
        $total = 0.0;

        foreach ($graded as $activity) {
            $weight = self::weight($activity);
            $earned += $weight * (float) ($grades[$activity['id']]['grade_percent'] ?? 0);
            $total += $weight;
        }

        return $total > 0 ? round($earned / $total, 2) : null;
    }

    /**
     * Las versiones publicadas antes de existir el peso valen 1 por actividad.
     *
     * @param  array<string, mixed>  $activity
     */
    public static function weight(array $activity): float
    {
        return (float) ($activity['weight'] ?? 1);
    }

    /**
     * Notas del propio alumno en un curso.
     *
     * @return array<string, mixed>
     */
    public function forStudent(Enrollment $enrollment): array
    {
        $version = $enrollment->course->currentVersion;
        $activities = $version ? ClassroomPresenter::activitiesOf($version) : [];
        $row = $this->rows($enrollment->course_id, $activities, collect([$enrollment]))[$enrollment->id];

        return [
            'final_percent' => $row['final_percent'],
            'items' => array_map(fn (array $activity) => [
                'id' => $activity['id'],
                'type' => $activity['type'],
                'title' => $activity['title'],
                'section' => $activity['section'],
                'weight' => self::weight($activity),
            ] + $row['grades'][$activity['id']], $this->gradedActivities($activities)),
        ];
    }

    /**
     * Libro de notas en Excel, con las notas en la escala de la academia.
     *
     * @param  list<array<string, mixed>>  $activities
     * @param  Collection<int, Enrollment>  $enrollments  con el alumno cargado
     * @return string ruta del archivo temporal generado
     */
    public function export(string $courseId, array $activities, Collection $enrollments, int $scale): string
    {
        $graded = $this->gradedActivities($activities);
        $rows = $this->rows($courseId, $activities, $enrollments);
        $toScale = fn (?float $percent): ?float => $percent === null ? null : round($percent * $scale / 100, 2);

        $headers = ['Alumno', 'Correo', 'Documento'];
        foreach ($graded as $activity) {
            $headers[] = "{$activity['title']} (peso ".rtrim(rtrim(number_format(self::weight($activity), 2, '.', ''), '0'), '.').')';
        }
        $headers[] = "Nota final (0 a {$scale})";

        $lines = [];
        foreach ($enrollments as $enrollment) {
            $line = [
                trim("{$enrollment->student->first_name} {$enrollment->student->last_name}"),
                $enrollment->student->email,
                (string) $enrollment->student->doc_number,
            ];
            foreach ($graded as $activity) {
                $line[] = $toScale($rows[$enrollment->id]['grades'][$activity['id']]['grade_percent'] ?? null) ?? '';
            }
            $line[] = $toScale($rows[$enrollment->id]['final_percent']) ?? '';
            $lines[] = $line;
        }

        return Spreadsheet::write('Notas', $headers, $lines);
    }
}
