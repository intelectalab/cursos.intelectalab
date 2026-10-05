<?php

namespace App\Modules\Courses\Http;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;

/**
 * Ficha de un curso para quien aún no está matriculado: el catálogo público
 * y "Cursos disponibles" del alumno. Muestra la versión publicada, nunca el
 * borrador, y el temario sin su contenido (RN-003).
 */
class CatalogPresenter
{
    /**
     * Espera currentVersion cargada.
     *
     * @param  string  $currency  código de la moneda de la organización
     * @return array<string, mixed>
     */
    public static function card(Course $course, string $currency): array
    {
        $snapshot = $course->currentVersion->snapshot;
        $sections = collect($snapshot['sections'] ?? []);

        return [
            'id' => $course->id,
            'title' => $snapshot['title'],
            'summary' => $snapshot['summary'] ?? null,
            'category' => $snapshot['category'] ?? null,
            'level' => $snapshot['level'] ?? null,
            'duration_hours' => $snapshot['duration_hours'] ?? null,
            'language' => $snapshot['language'] ?? null,
            'cover_url' => ($snapshot['cover_file_id'] ?? null) ? "/api/v1/public-files/{$snapshot['cover_file_id']}" : null,
            'sections_count' => $sections->count(),
            'resources_count' => $sections->sum(fn (array $section) => count($section['resources'] ?? [])),
            'activities_count' => $sections->sum(fn (array $section) => count($section['activities'] ?? [])),
            // null = no está en venta (solo matrícula manual); 0 = gratuito
            'price' => $course->price,
            'currency' => $currency,
            'issues_certificate' => $course->issues_certificate,
        ];
    }

    /**
     * Espera currentVersion y teachers cargadas.
     *
     * @return array<string, mixed>
     */
    public static function detail(Course $course, string $currency): array
    {
        $snapshot = $course->currentVersion->snapshot;

        return self::card($course, $currency) + [
            'description' => $snapshot['description'] ?? null,
            'access_days' => $course->access_days,
            'teachers' => $course->teachers->map(fn (User $teacher) => trim("{$teacher->first_name} {$teacher->last_name}"))->values(),
            // Temario: títulos y tipos, sin el contenido
            'sections' => array_map(fn (array $section) => [
                'title' => $section['title'],
                'items' => array_merge(
                    array_map(fn (array $resource) => ['kind' => 'RESOURCE', 'type' => $resource['type'], 'title' => $resource['title']], $section['resources'] ?? []),
                    array_map(fn (array $activity) => ['kind' => 'ACTIVITY', 'type' => $activity['type'], 'title' => $activity['title']], $section['activities'] ?? []),
                ),
            ], $snapshot['sections'] ?? []),
        ];
    }
}
