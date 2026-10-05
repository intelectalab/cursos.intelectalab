<?php

namespace App\Modules\Enrollments\Http;

use App\Modules\Courses\Domain\Models\CourseVersion;
use App\Modules\Courses\Domain\YouTube;
use App\Modules\Enrollments\Domain\Models\Enrollment;

/**
 * Lo que ve el alumno: siempre la versión publicada vigente del curso,
 * nunca el borrador. De las actividades solo se entrega la ficha; las
 * preguntas (y sus respuestas) no salen de aquí.
 */
class ClassroomPresenter
{
    /**
     * Tarjeta de "Mis cursos".
     *
     * @return array<string, mixed>
     */
    public static function summary(Enrollment $enrollment): array
    {
        $version = $enrollment->course->currentVersion;
        $snapshot = $version->snapshot;
        $sections = collect($snapshot['sections'] ?? []);

        return [
            'course_id' => $enrollment->course_id,
            'title' => $snapshot['title'],
            'summary' => $snapshot['summary'] ?? null,
            'category' => $snapshot['category'] ?? null,
            'level' => $snapshot['level'] ?? null,
            'duration_hours' => $snapshot['duration_hours'] ?? null,
            'language' => $snapshot['language'] ?? null,
            'cover_url' => ($snapshot['cover_file_id'] ?? null) ? "/api/v1/public-files/{$snapshot['cover_file_id']}" : null,
            'enrolled_at' => $enrollment->enrolled_at->toIso8601String(),
            'state' => $enrollment->state(),
            'starts_at' => $enrollment->starts_at?->toDateString(),
            'ends_at' => $enrollment->ends_at?->toDateString(),
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
            'sections_count' => $sections->count(),
            'resources_count' => $sections->sum(fn (array $section) => count($section['resources'] ?? [])),
            'activities_count' => $sections->sum(fn (array $section) => count($section['activities'] ?? [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function classroom(Enrollment $enrollment): array
    {
        $version = $enrollment->course->currentVersion;

        return self::summary($enrollment) + [
            'description' => $version->snapshot['description'] ?? null,
            'sections' => array_map(fn (array $section) => [
                'id' => $section['id'],
                'title' => $section['title'],
                'resources' => array_map(fn (array $resource) => self::resource($resource), $section['resources'] ?? []),
                'activities' => array_map(fn (array $activity) => self::activity($activity), $section['activities'] ?? []),
            ], $version->snapshot['sections'] ?? []),
        ];
    }

    /**
     * Archivo de un recurso de la versión vigente, o null si el recurso no está en ella.
     *
     * @return array{id: string, name: string}|null
     */
    public static function fileOf(CourseVersion $version, string $resourceId): ?array
    {
        foreach ($version->snapshot['sections'] ?? [] as $section) {
            foreach ($section['resources'] ?? [] as $resource) {
                if ($resource['id'] === $resourceId) {
                    return $resource['file'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * Actividad tal como se publicó, con todo su contenido (preguntas y
     * respuestas incluidas): es para el servidor, no para enviarla al alumno.
     *
     * @return array<string, mixed>|null
     */
    public static function activityOf(CourseVersion $version, string $activityId): ?array
    {
        foreach ($version->snapshot['sections'] ?? [] as $section) {
            foreach ($section['activities'] ?? [] as $activity) {
                if ($activity['id'] === $activityId) {
                    return $activity;
                }
            }
        }

        return null;
    }

    /**
     * Actividades de la versión, en orden, con el título de su sección.
     *
     * @return list<array<string, mixed>>
     */
    public static function activitiesOf(CourseVersion $version): array
    {
        $activities = [];

        foreach ($version->snapshot['sections'] ?? [] as $section) {
            foreach ($section['activities'] ?? [] as $activity) {
                $activities[] = $activity + ['section' => $section['title']];
            }
        }

        return $activities;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private static function resource(array $resource): array
    {
        return [
            'id' => $resource['id'],
            'type' => $resource['type'],
            'title' => $resource['title'],
            'url' => $resource['url'] ?? null,
            'video_id' => $resource['type'] === 'VIDEO' ? YouTube::videoId($resource['url'] ?? null) : null,
            'body' => $resource['body'] ?? null,
            'file' => ($resource['file'] ?? null) ? [
                'name' => $resource['file']['name'],
                'size_bytes' => $resource['file']['size_bytes'],
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private static function activity(array $activity): array
    {
        $base = [
            'id' => $activity['id'],
            'type' => $activity['type'],
            'title' => $activity['title'],
            'description' => $activity['description'] ?? null,
        ];

        return $base + match ($activity['type']) {
            'QUIZ' => [
                'questions_count' => count($activity['quiz']['questions'] ?? []),
                'passing_percent' => $activity['quiz']['passing_percent'] ?? null,
                'max_attempts' => $activity['quiz']['max_attempts'] ?? null,
                'time_limit_minutes' => $activity['quiz']['time_limit_minutes'] ?? null,
            ],
            'TASK' => [
                'allow_text' => $activity['task']['allow_text'] ?? false,
                'allow_file' => $activity['task']['allow_file'] ?? false,
            ],
            'SURVEY' => ['questions_count' => count($activity['survey']['questions'] ?? [])],
            default => [],
        };
    }
}
