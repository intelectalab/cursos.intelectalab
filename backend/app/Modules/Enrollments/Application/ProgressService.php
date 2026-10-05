<?php

namespace App\Modules\Enrollments\Application;

use App\Modules\Courses\Domain\Models\CourseVersion;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use Illuminate\Support\Collection;

/**
 * Avance del alumno (RN-005): se calcula en el servidor sobre el contenido
 * de la versión publicada vigente. Todo pesa igual: cada recurso y cada
 * actividad cuenta como un elemento. Lo completado en una versión anterior
 * sigue contando mientras el elemento exista en la vigente.
 */
class ProgressService
{
    /**
     * Marca un elemento como completado. Repetirlo no cambia nada.
     */
    public function complete(Enrollment $enrollment, string $type, string $itemId): void
    {
        EnrollmentProgress::query()->firstOrCreate(
            ['enrollment_id' => $enrollment->id, 'item_type' => $type, 'item_id' => $itemId],
            ['completed_at' => now()]
        );

        $this->summary($enrollment);
    }

    /**
     * @return array{percent: int, completed: int, total: int, resource_ids: list<string>, activity_ids: list<string>}
     */
    public function summary(Enrollment $enrollment): array
    {
        return $this->markIfCompleted($enrollment, $this->summarize(
            $this->items($enrollment->course->currentVersion),
            EnrollmentProgress::query()->where('enrollment_id', $enrollment->id)->get(['item_type', 'item_id'])
        ));
    }

    /**
     * Avance de varias matrículas del mismo curso con una sola consulta.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array<string, array{percent: int, completed: int, total: int, resource_ids: list<string>, activity_ids: list<string>}>
     */
    public function summaries(CourseVersion $version, Collection $enrollments): array
    {
        $items = $this->items($version);
        $rows = EnrollmentProgress::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get(['enrollment_id', 'item_type', 'item_id'])
            ->groupBy('enrollment_id');

        return $enrollments
            ->mapWithKeys(fn (Enrollment $enrollment) => [
                $enrollment->id => $this->markIfCompleted($enrollment, $this->summarize($items, $rows->get($enrollment->id, collect()))),
            ])
            ->all();
    }

    /**
     * El curso queda completado la primera vez que su avance llega al 100 %;
     * contenido publicado después no le quita esa condición. Se comprueba cada
     * vez que se calcula el avance, así también alcanza a matrículas antiguas.
     *
     * @param  array{percent: int, completed: int, total: int, resource_ids: list<string>, activity_ids: list<string>}  $summary
     * @return array{percent: int, completed: int, total: int, resource_ids: list<string>, activity_ids: list<string>}
     */
    private function markIfCompleted(Enrollment $enrollment, array $summary): array
    {
        if ($summary['percent'] === 100 && $enrollment->completed_at === null && $enrollment->isActive()) {
            $enrollment->update(['completed_at' => now()]);
        }

        return $summary;
    }

    /**
     * Elementos de la versión publicada que cuentan para el avance.
     *
     * @return array{RESOURCE: list<string>, ACTIVITY: list<string>}
     */
    public function items(CourseVersion $version): array
    {
        $sections = collect($version->snapshot['sections'] ?? []);

        return [
            EnrollmentProgress::TYPE_RESOURCE => $sections->flatMap(fn (array $section) => array_column($section['resources'] ?? [], 'id'))->all(),
            EnrollmentProgress::TYPE_ACTIVITY => $sections->flatMap(fn (array $section) => array_column($section['activities'] ?? [], 'id'))->all(),
        ];
    }

    /**
     * @param  array{RESOURCE: list<string>, ACTIVITY: list<string>}  $items
     * @param  Collection<int, EnrollmentProgress>  $rows
     * @return array{percent: int, completed: int, total: int, resource_ids: list<string>, activity_ids: list<string>}
     */
    private function summarize(array $items, Collection $rows): array
    {
        $done = fn (string $type) => array_values(array_intersect(
            $items[$type],
            $rows->where('item_type', $type)->pluck('item_id')->all()
        ));

        $resources = $done(EnrollmentProgress::TYPE_RESOURCE);
        $activities = $done(EnrollmentProgress::TYPE_ACTIVITY);
        $total = count($items[EnrollmentProgress::TYPE_RESOURCE]) + count($items[EnrollmentProgress::TYPE_ACTIVITY]);
        $completed = count($resources) + count($activities);

        return [
            'percent' => $total > 0 ? (int) floor($completed * 100 / $total) : 0,
            'completed' => $completed,
            'total' => $total,
            'resource_ids' => $resources,
            'activity_ids' => $activities,
        ];
    }
}
