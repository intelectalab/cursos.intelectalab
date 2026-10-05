<?php

namespace App\Modules\Courses\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseResource;
use App\Modules\Courses\Domain\Models\CourseSection;
use App\Modules\Courses\Domain\Models\CourseVersion;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida del curso (RF-COURSE-001). El curso se edita siempre como
 * borrador; publicar guarda una copia numerada que es la que verán los
 * alumnos, de modo que una edición a medias nunca queda a la vista.
 */
class CourseService
{
    public const COVER_PURPOSE = 'course_cover';

    private const EDITABLE = ['title', 'summary', 'description', 'category', 'level', 'duration_hours', 'language'];

    /** Se aplican al guardar, sin pasar por una publicación */
    private const COMMERCIAL = ['access_days', 'price', 'issues_certificate'];

    public function __construct(
        private readonly FileStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Course
    {
        return DB::transaction(function () use ($actor, $data) {
            $course = Course::create($this->attributes($data) + [
                'access_days' => $data['access_days'] ?? null,
                'price' => $data['price'] ?? null,
                'issues_certificate' => $data['issues_certificate'] ?? false,
                'status' => Course::STATUS_DRAFT,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('courses.created', $course, null, $course->only(self::EDITABLE));

            return $course;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Course $course, array $data): Course
    {
        $this->assertNotArchived($course);

        return DB::transaction(function () use ($course, $data) {
            $before = $course->only(self::EDITABLE);
            $course->fill($this->attributes($data));

            if ($course->isDirty()) {
                $course->has_pending_changes = true;
                $course->save();

                $this->audit->record('courses.updated', $course, $before, $course->only(self::EDITABLE));
            }

            // Reglas de matrícula, venta y certificación, no contenido: no generan cambios por publicar
            $course->access_days = $data['access_days'] ?? null;
            $course->price = $data['price'] ?? null;
            $course->issues_certificate = $data['issues_certificate'] ?? false;

            if ($course->isDirty(self::COMMERCIAL)) {
                $previous = array_intersect_key($course->getOriginal(), $course->getDirty());
                $course->save();

                $this->audit->record('courses.updated', $course, $previous, array_intersect_key($course->only(self::COMMERCIAL), $previous));
            }

            return $course;
        });
    }

    public function replaceCover(Course $course, UploadedFile $upload): Course
    {
        $this->assertNotArchived($course);
        $previous = $course->cover;

        $course->update([
            'cover_file_id' => $this->storage->store($upload, self::COVER_PURPOSE)->id,
            'has_pending_changes' => true,
        ]);

        $this->releaseFile($course, $previous);

        return $course;
    }

    public function removeCover(Course $course): Course
    {
        $this->assertNotArchived($course);
        $previous = $course->cover;

        if ($previous) {
            $course->update(['cover_file_id' => null, 'has_pending_changes' => true]);
            $this->releaseFile($course, $previous);
        }

        return $course;
    }

    /**
     * @param  list<string>  $userIds
     */
    public function setTeachers(Course $course, array $userIds): Course
    {
        return DB::transaction(function () use ($course, $userIds) {
            $before = $course->teachers()->pluck('users.id')->sort()->values()->all();
            $course->teachers()->sync($userIds);
            $after = collect($userIds)->sort()->values()->all();

            if ($before !== $after) {
                $this->audit->record('courses.teachers_updated', $course, ['teachers' => $before], ['teachers' => $after]);
            }

            return $course;
        });
    }

    public function publish(Course $course, User $actor, ?string $note): CourseVersion
    {
        $this->assertNotArchived($course);

        $course->load(['sections.resources.file']);
        $snapshot = $this->snapshot($course);

        $empty = collect($snapshot['sections'])->every(
            fn (array $section) => collect($section)->except(['id', 'title'])->every(fn (array $items) => $items === [])
        );

        if ($empty) {
            throw new ApiException(
                ErrorCode::CourseNotPublishable,
                'Para publicar, el curso necesita al menos una sección con un recurso o una actividad.',
                422
            );
        }

        $problems = collect($this->extensions())->flatMap(fn (CourseContentExtension $extension) => $extension->publishProblems($course));

        if ($problems->isNotEmpty()) {
            throw new ApiException(ErrorCode::CourseNotPublishable, $problems->first(), 422, ['problems' => $problems->all()]);
        }

        if ($course->status === Course::STATUS_PUBLISHED && ! $course->has_pending_changes) {
            throw new ApiException(ErrorCode::CourseNotPublishable, 'No hay cambios nuevos que publicar.', 422);
        }

        return DB::transaction(function () use ($course, $actor, $note, $snapshot) {
            // Bloquea el curso: dos publicaciones simultáneas no repiten número
            Course::query()->whereKey($course->id)->lockForUpdate()->first();

            $version = CourseVersion::create([
                'course_id' => $course->id,
                'number' => (int) CourseVersion::query()->where('course_id', $course->id)->max('number') + 1,
                'note' => $note,
                'snapshot' => $snapshot,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            $course->update([
                'status' => Course::STATUS_PUBLISHED,
                'current_version_id' => $version->id,
                'has_pending_changes' => false,
                'published_at' => $version->published_at,
            ]);

            $this->audit->record('courses.published', $course, null, ['version' => $version->number, 'note' => $note]);

            return $version;
        });
    }

    public function archive(Course $course): Course
    {
        $this->assertNotArchived($course);

        return DB::transaction(function () use ($course) {
            $before = $course->status;
            $course->update(['status' => Course::STATUS_ARCHIVED, 'archived_at' => now()]);

            $this->audit->record('courses.archived', $course, ['status' => $before], ['status' => $course->status]);

            return $course;
        });
    }

    /**
     * Vuelve al estado que tenía: publicado si ya tuvo una versión, borrador si no.
     */
    public function restore(Course $course): Course
    {
        if (! $course->isArchived()) {
            throw new ApiException(ErrorCode::CourseInvalidState, 'El curso no está archivado.', 409);
        }

        return DB::transaction(function () use ($course) {
            $course->update([
                'status' => $course->current_version_id ? Course::STATUS_PUBLISHED : Course::STATUS_DRAFT,
                'archived_at' => null,
            ]);

            $this->audit->record('courses.restored', $course, ['status' => Course::STATUS_ARCHIVED], ['status' => $course->status]);

            return $course;
        });
    }

    public function assertNotArchived(Course $course): void
    {
        if ($course->isArchived()) {
            throw new ApiException(
                ErrorCode::CourseInvalidState,
                'El curso está archivado. Restáuralo para poder modificarlo.',
                409
            );
        }
    }

    /**
     * Borra un archivo que el borrador dejó de usar, salvo que una versión
     * publicada todavía lo necesite.
     */
    public function releaseFile(Course $course, ?File $file): void
    {
        if (! $file) {
            return;
        }

        $published = CourseVersion::query()
            ->where('course_id', $course->id)
            ->whereRaw('snapshot::text like ?', ['%'.$file->id.'%'])
            ->exists();

        if (! $published) {
            $this->storage->delete($file);
        }
    }

    /**
     * Un campo opcional que no llega queda vacío: guardar reemplaza todos los datos.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return array_merge(array_fill_keys(self::EDITABLE, null), array_intersect_key($data, array_flip(self::EDITABLE)));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Course $course): array
    {
        $extra = [];

        foreach ($this->extensions() as $extension) {
            $extra[$extension->key()] = $extension->snapshot($course);
        }

        return $course->only(self::EDITABLE) + [
            'cover_file_id' => $course->cover_file_id,
            'sections' => $course->sections->map(fn (CourseSection $section) => [
                'id' => $section->id,
                'title' => $section->title,
                'resources' => $section->resources->map(fn (CourseResource $resource) => [
                    'id' => $resource->id,
                    'type' => $resource->type,
                    'title' => $resource->title,
                    'url' => $resource->url,
                    'body' => $resource->body,
                    'file' => $resource->file ? [
                        'id' => $resource->file->id,
                        'name' => $resource->file->original_name,
                        'mime_type' => $resource->file->mime_type,
                        'size_bytes' => $resource->file->size_bytes,
                    ] : null,
                ])->all(),
            ] + array_map(fn (array $bySection) => $bySection[$section->id] ?? [], $extra))->all(),
        ];
    }

    /**
     * @return iterable<CourseContentExtension>
     */
    private function extensions(): iterable
    {
        return app()->tagged(CourseContentExtension::TAG);
    }
}
