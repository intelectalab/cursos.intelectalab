<?php

namespace App\Modules\Courses\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseAnnouncement;
use App\Modules\Courses\Domain\Models\CourseReview;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Anuncios del docente a sus alumnos y reseñas de los alumnos sobre el curso.
 */
class CourseCommunity
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantNotifier $notifier,
    ) {}

    /**
     * Publica un anuncio y, si se pide, avisa por correo a los alumnos con acceso.
     *
     * @param  array{title: string, body: string, notify?: bool}  $data
     */
    public function announce(Course $course, array $data, User $author): CourseAnnouncement
    {
        $announcement = DB::transaction(function () use ($course, $data, $author) {
            $announcement = CourseAnnouncement::create([
                'course_id' => $course->id,
                'author_id' => $author->id,
                'title' => trim($data['title']),
                'body' => trim($data['body']),
                'emailed_at' => ($data['notify'] ?? false) ? now() : null,
            ]);

            $this->audit->record('announcements.published', $announcement, null, ['course' => $course->title, 'title' => $announcement->title, 'emailed' => $announcement->emailed_at !== null]);

            return $announcement;
        });

        if ($announcement->emailed_at !== null) {
            $students = User::query()->whereIn('id', Enrollment::query()->where('course_id', $course->id)->accessible()->select('student_id'))->get();

            foreach ($students as $student) {
                $this->notifier->notify($student, 'anuncio_curso', [
                    'curso' => $course->title,
                    'titulo' => $announcement->title,
                    'mensaje' => $announcement->body,
                ], "/my-courses/{$course->id}");
            }
        }

        return $announcement;
    }

    /**
     * @param  array{title: string, body: string}  $data
     */
    public function updateAnnouncement(CourseAnnouncement $announcement, array $data): CourseAnnouncement
    {
        $before = ['title' => $announcement->title];
        $announcement->update(['title' => trim($data['title']), 'body' => trim($data['body'])]);
        $this->audit->record('announcements.updated', $announcement, $before, ['title' => $announcement->title]);

        return $announcement;
    }

    public function deleteAnnouncement(CourseAnnouncement $announcement): void
    {
        $this->audit->record('announcements.deleted', $announcement, ['title' => $announcement->title], null);
        $announcement->delete();
    }

    /**
     * El alumno opina del curso: una reseña por persona, que puede cambiar.
     * Si la academia la había ocultado y la cambia, vuelve a verse.
     *
     * @param  array{rating: int, comment?: ?string}  $data
     */
    public function review(Course $course, User $student, array $data): CourseReview
    {
        $enrolled = Enrollment::query()->where('course_id', $course->id)->where('student_id', $student->id)->where('status', Enrollment::STATUS_ACTIVE)->exists();

        if (! $enrolled) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }

        $review = CourseReview::query()->firstOrNew(['course_id' => $course->id, 'student_id' => $student->id]);
        $created = ! $review->exists;
        $review->fill([
            'rating' => $data['rating'],
            'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null,
            'status' => CourseReview::STATUS_VISIBLE,
            'hidden_reason' => null,
            'hidden_by' => null,
            'hidden_at' => null,
        ])->save();

        $this->audit->record($created ? 'reviews.created' : 'reviews.updated', $review, null, ['course' => $course->title, 'rating' => $review->rating], $student->id);

        return $review;
    }

    public function hide(CourseReview $review, string $reason, User $actor): CourseReview
    {
        $review->update(['status' => CourseReview::STATUS_HIDDEN, 'hidden_reason' => trim($reason), 'hidden_by' => $actor->id, 'hidden_at' => now()]);
        $this->audit->record('reviews.hidden', $review, ['status' => CourseReview::STATUS_VISIBLE], ['status' => CourseReview::STATUS_HIDDEN, 'reason' => $review->hidden_reason]);

        return $review;
    }

    public function show(CourseReview $review): CourseReview
    {
        $review->update(['status' => CourseReview::STATUS_VISIBLE, 'hidden_reason' => null, 'hidden_by' => null, 'hidden_at' => null]);
        $this->audit->record('reviews.shown', $review, ['status' => CourseReview::STATUS_HIDDEN], ['status' => CourseReview::STATUS_VISIBLE]);

        return $review;
    }

    /**
     * Promedio y cantidad de reseñas visibles de varios cursos, en una consulta.
     *
     * @param  iterable<string>  $courseIds
     * @return Collection<string, array{average: float, count: int}>
     */
    public static function ratings(iterable $courseIds): Collection
    {
        return CourseReview::query()
            ->whereIn('course_id', collect($courseIds)->all())
            ->where('status', CourseReview::STATUS_VISIBLE)
            ->groupBy('course_id')
            ->selectRaw('course_id, avg(rating) as average, count(*) as total')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->course_id => ['average' => round((float) $row->average, 1), 'count' => (int) $row->total]]);
    }

    /**
     * Cómo se muestra a quien escribió una reseña en el catálogo público: nombre e inicial del apellido.
     */
    public static function publicName(User $student): string
    {
        $initial = mb_substr(trim((string) $student->last_name), 0, 1);

        return trim($student->first_name.($initial !== '' ? " {$initial}." : ''));
    }
}
