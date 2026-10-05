<?php

namespace App\Modules\Courses\Application;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseResource;
use App\Modules\Courses\Domain\Models\CourseSection;
use App\Modules\Courses\Domain\YouTube;
use App\Modules\Files\Application\FileStorage;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Estructura del curso (RF-CONT-001): secciones y recursos del borrador.
 * Cada cambio deja el curso con cambios pendientes de publicar.
 */
class CourseContentService
{
    public function __construct(
        private readonly CourseService $courses,
        private readonly FileStorage $storage,
    ) {}

    public function addSection(Course $course, string $title): CourseSection
    {
        $this->courses->assertNotArchived($course);

        return $this->changing($course, fn () => $course->sections()->create([
            'title' => $title,
            'position' => $this->nextPosition($course->sections()),
        ]));
    }

    public function renameSection(Course $course, CourseSection $section, string $title): CourseSection
    {
        $this->courses->assertNotArchived($course);

        return $this->changing($course, function () use ($section, $title) {
            $section->update(['title' => $title]);

            return $section;
        });
    }

    public function deleteSection(Course $course, CourseSection $section): void
    {
        $this->courses->assertNotArchived($course);
        $files = $section->resources()->with('file')->get()->pluck('file')->filter();

        $this->changing($course, fn () => $section->delete());

        $files->each(fn ($file) => $this->courses->releaseFile($course, $file));
    }

    /**
     * @param  list<string>  $ids  todas las secciones del curso, en su nuevo orden
     */
    public function reorderSections(Course $course, array $ids): void
    {
        $this->courses->assertNotArchived($course);
        $this->changing($course, fn () => $this->reorder($course->sections(), $ids));
    }

    /**
     * @param  array{type: string, title: string, url?: ?string, body?: ?string}  $data
     */
    public function addResource(Course $course, CourseSection $section, array $data, ?UploadedFile $upload): CourseResource
    {
        $this->courses->assertNotArchived($course);

        return $this->changing($course, fn () => $section->resources()->create([
            'course_id' => $course->id,
            'type' => $data['type'],
            'title' => $data['title'],
            'position' => $this->nextPosition($section->resources()),
            'url' => $this->url($data['type'], $data),
            'body' => $data['type'] === CourseResource::TYPE_TEXT ? $data['body'] : null,
            'file_id' => $upload ? $this->storage->store($upload, CourseResource::FILE_PURPOSE)->id : null,
        ]));
    }

    /**
     * El tipo de un recurso no cambia: se elimina y se crea otro.
     *
     * @param  array{title: string, url?: ?string, body?: ?string}  $data
     */
    public function updateResource(Course $course, CourseResource $resource, array $data, ?UploadedFile $upload): CourseResource
    {
        $this->courses->assertNotArchived($course);
        $previous = $upload ? $resource->file : null;

        $this->changing($course, fn () => $resource->update([
            'title' => $data['title'],
            'url' => $this->url($resource->type, $data),
            'body' => $resource->type === CourseResource::TYPE_TEXT ? $data['body'] : null,
            'file_id' => $upload ? $this->storage->store($upload, CourseResource::FILE_PURPOSE)->id : $resource->file_id,
        ]));

        $this->courses->releaseFile($course, $previous);

        return $resource;
    }

    public function deleteResource(Course $course, CourseResource $resource): void
    {
        $this->courses->assertNotArchived($course);
        $file = $resource->file;

        $this->changing($course, fn () => $resource->delete());

        $this->courses->releaseFile($course, $file);
    }

    /**
     * @param  list<string>  $ids  todos los recursos de la sección, en su nuevo orden
     */
    public function reorderResources(Course $course, CourseSection $section, array $ids): void
    {
        $this->courses->assertNotArchived($course);
        $this->changing($course, fn () => $this->reorder($section->resources(), $ids));
    }

    /**
     * Un video se guarda con su dirección canónica de YouTube, sin importar
     * el formato en que se pegó.
     *
     * @param  array<string, mixed>  $data
     */
    private function url(string $type, array $data): ?string
    {
        return match ($type) {
            CourseResource::TYPE_VIDEO => YouTube::watchUrl((string) YouTube::videoId($data['url'])),
            CourseResource::TYPE_LINK => $data['url'],
            default => null,
        };
    }

    private function changing(Course $course, callable $change): mixed
    {
        return DB::transaction(function () use ($course, $change) {
            $result = $change();
            $course->update(['has_pending_changes' => true]);

            return $result;
        });
    }

    /**
     * @param  HasMany<covariant Model, covariant Model>  $relation
     */
    private function nextPosition(HasMany $relation): int
    {
        return (int) $relation->reorder()->max('position') + 1;
    }

    /**
     * @param  HasMany<covariant Model, covariant Model>  $relation
     * @param  list<string>  $ids
     */
    private function reorder(HasMany $relation, array $ids): void
    {
        $current = $relation->pluck('id')->all();

        if (count($ids) !== count($current) || array_diff($current, $ids) !== []) {
            throw new ApiException(
                ErrorCode::ValidationError,
                'El orden debe incluir exactamente los elementos actuales.',
                422,
                ['ids' => ['La lista no coincide con los elementos actuales.']]
            );
        }

        foreach (array_values($ids) as $index => $id) {
            $relation->getRelated()->newQuery()->whereKey($id)->update(['position' => $index + 1]);
        }
    }
}
