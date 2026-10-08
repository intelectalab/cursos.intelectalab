<?php

namespace App\Modules\Enrollments\Application;

use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;

/**
 * Avance secuencial (YC-AULA-003): en un curso que lo exige, una sección se
 * abre cuando todas las anteriores están completas. Dentro de una sección
 * abierta el alumno avanza en el orden que quiera.
 */
class SequenceGate
{
    public function __construct(private readonly ProgressService $progress) {}

    /**
     * Secciones de la versión vigente que el alumno todavía no puede abrir.
     *
     * @param  array{resource_ids: list<string>, activity_ids: list<string>}|null  $summary  avance ya calculado, si se tiene
     * @return list<string>
     */
    public function lockedSectionIds(Enrollment $enrollment, ?array $summary = null): array
    {
        if (! $enrollment->course->sequential) {
            return [];
        }

        $summary ??= $this->progress->summary($enrollment);
        $done = array_merge($summary['resource_ids'], $summary['activity_ids']);
        $locked = [];
        $open = true;

        foreach ($enrollment->course->currentVersion->snapshot['sections'] ?? [] as $section) {
            if (! $open) {
                $locked[] = $section['id'];

                continue;
            }

            // Una sección pendiente cierra todas las que vienen después
            $open = array_diff($this->itemIds($section), $done) === [];
        }

        return $locked;
    }

    /**
     * Rechaza el recurso o la actividad de una sección aún cerrada.
     */
    public function assertOpen(Enrollment $enrollment, string $itemId): void
    {
        $locked = $this->lockedSectionIds($enrollment);

        if ($locked === []) {
            return;
        }

        foreach ($enrollment->course->currentVersion->snapshot['sections'] ?? [] as $section) {
            if (in_array($section['id'], $locked, true) && in_array($itemId, $this->itemIds($section), true)) {
                throw new ApiException(ErrorCode::SectionLocked, 'Completa las secciones anteriores para abrir esta.', 403);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<string>
     */
    private function itemIds(array $section): array
    {
        return array_merge(array_column($section['resources'] ?? [], 'id'), array_column($section['activities'] ?? [], 'id'));
    }
}
