<?php

namespace App\Modules\Courses\Http;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseResource;
use App\Modules\Courses\Domain\Models\CourseSection;
use App\Modules\Courses\Domain\Models\CourseVersion;
use App\Modules\Courses\Domain\YouTube;
use App\Modules\Identity\Domain\Models\User;

class CoursePresenter
{
    /**
     * Fila del listado. Espera cover, currentVersion y teachers cargados,
     * y los conteos sections_count y resources_count.
     *
     * @return array<string, mixed>
     */
    public static function summary(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'summary' => $course->summary,
            'category' => $course->category,
            'level' => $course->level,
            'duration_hours' => $course->duration_hours,
            // Días de acceso que recibe cada matrícula nueva; null = no vence
            'access_days' => $course->access_days,
            // Precio de venta en la moneda de la organización; null = no está en venta, 0 = gratuito
            'price' => $course->price,
            'issues_certificate' => $course->issues_certificate,
            'sequential' => $course->sequential,
            'language' => $course->language,
            'status' => $course->status,
            'cover_url' => $course->cover?->publicUrl(),
            'version' => $course->currentVersion?->number,
            // Solo importa en un curso publicado: el borrador aún no tiene qué comparar
            'pending_changes' => $course->status === Course::STATUS_PUBLISHED && $course->has_pending_changes,
            'published_at' => $course->published_at?->toIso8601String(),
            'updated_at' => $course->updated_at?->toIso8601String(),
            'sections_count' => $course->sections_count ?? $course->sections->count(),
            'resources_count' => $course->resources_count ?? $course->resources()->count(),
            'teachers' => $course->teachers->map(fn (User $user) => self::teacher($user))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Course $course): array
    {
        $course->loadMissing(['cover', 'currentVersion', 'teachers', 'sections.resources.file', 'versions.publisher']);

        return self::summary($course) + [
            'description' => $course->description,
            'sections' => $course->sections->map(fn (CourseSection $section) => self::section($section))->values(),
            'versions' => $course->versions->map(fn (CourseVersion $version) => self::version($version))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function section(CourseSection $section): array
    {
        return [
            'id' => $section->id,
            'title' => $section->title,
            'resources' => $section->resources->map(fn (CourseResource $resource) => self::resource($resource))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resource(CourseResource $resource): array
    {
        return [
            'id' => $resource->id,
            'type' => $resource->type,
            'title' => $resource->title,
            'url' => $resource->url,
            // Para incrustar el reproductor y mostrar la miniatura
            'video_id' => $resource->type === CourseResource::TYPE_VIDEO ? YouTube::videoId($resource->url) : null,
            'body' => $resource->body,
            'file' => $resource->file ? [
                'name' => $resource->file->original_name,
                'mime_type' => $resource->file->mime_type,
                'size_bytes' => $resource->file->size_bytes,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(CourseVersion $version): array
    {
        $sections = collect($version->snapshot['sections'] ?? []);

        return [
            'id' => $version->id,
            'number' => $version->number,
            'note' => $version->note,
            'published_at' => $version->published_at->toIso8601String(),
            'published_by' => $version->publisher ? trim("{$version->publisher->first_name} {$version->publisher->last_name}") : null,
            'sections_count' => $sections->count(),
            'resources_count' => $sections->sum(fn (array $section) => count($section['resources'] ?? [])),
        ];
    }

    /**
     * @return array{id: string, name: string, email: string}
     */
    public static function teacher(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
        ];
    }
}
