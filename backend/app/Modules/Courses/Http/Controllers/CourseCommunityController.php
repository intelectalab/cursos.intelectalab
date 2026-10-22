<?php

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Application\CourseCommunity;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseAnnouncement;
use App\Modules\Courses\Domain\Models\CourseReview;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anuncios y reseñas de un curso: el personal los ve y los gestiona desde
 * el curso; el alumno lee los anuncios y deja su reseña desde el aula.
 */
class CourseCommunityController extends Controller
{
    public function __construct(
        private readonly CourseCommunity $community,
        private readonly CourseAccess $access,
    ) {}

    /* ───────────── Personal ───────────── */

    public function announcements(Request $request, string $course): JsonResponse
    {
        $record = $this->viewable($request, $course);
        $user = $request->user();

        return ApiResponse::success(
            CourseAnnouncement::query()->where('course_id', $record->id)->with('author:id,first_name,last_name')->latest()->latest('id')->get()
                ->map(fn (CourseAnnouncement $announcement) => self::announcement($announcement) + [
                    'can_edit' => $announcement->author_id === $user->id || $user->hasPermission('courses.content.write'),
                ]),
            ['can_publish' => $this->access->canEditContent($user, $record)]
        );
    }

    public function publish(Request $request, string $course): JsonResponse
    {
        $record = $this->viewable($request, $course);
        $this->access->assertCanEditContent($request->user(), $record);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'notify' => ['boolean'],
        ], [], ['title' => 'título', 'body' => 'mensaje']);

        return ApiResponse::success(self::announcement($this->community->announce($record, $data, $request->user())->load('author')), null, 201);
    }

    public function updateAnnouncement(Request $request, string $course, string $announcement): JsonResponse
    {
        $item = $this->editable($request, $course, $announcement);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ], [], ['title' => 'título', 'body' => 'mensaje']);

        return ApiResponse::success(self::announcement($this->community->updateAnnouncement($item, $data)->load('author')));
    }

    public function deleteAnnouncement(Request $request, string $course, string $announcement): JsonResponse
    {
        $this->community->deleteAnnouncement($this->editable($request, $course, $announcement));

        return ApiResponse::success(null);
    }

    public function reviews(Request $request, string $course): JsonResponse
    {
        $record = $this->viewable($request, $course);
        $reviews = CourseReview::query()->where('course_id', $record->id)->with('student:id,first_name,last_name,email')->latest('updated_at')->get();

        return ApiResponse::success(
            $reviews->map(fn (CourseReview $review) => self::review($review) + [
                'student' => ['id' => $review->student->id, 'name' => trim("{$review->student->first_name} {$review->student->last_name}"), 'email' => $review->student->email],
                'hidden_reason' => $review->hidden_reason,
            ]),
            CourseCommunity::ratings([$record->id])->get($record->id, ['average' => null, 'count' => 0]) + ['can_moderate' => $request->user()->hasPermission('reviews.moderate')]
        );
    }

    public function hide(Request $request, string $course, string $review): JsonResponse
    {
        $item = $this->moderated($request, $course, $review);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], [], ['reason' => 'motivo']);

        return ApiResponse::success(self::review($this->community->hide($item, $data['reason'], $request->user())));
    }

    public function show(Request $request, string $course, string $review): JsonResponse
    {
        return ApiResponse::success(self::review($this->community->show($this->moderated($request, $course, $review))));
    }

    /* ───────────── Alumno ───────────── */

    public function myAnnouncements(Request $request, string $course): JsonResponse
    {
        $this->enrolled($request, $course);

        return ApiResponse::success(
            CourseAnnouncement::query()->where('course_id', $course)->with('author:id,first_name,last_name')->latest()->latest('id')->limit(20)->get()
                ->map(fn (CourseAnnouncement $announcement) => self::announcement($announcement))
        );
    }

    public function myReview(Request $request, string $course): JsonResponse
    {
        $this->enrolled($request, $course);
        $review = CourseReview::query()->where('course_id', $course)->where('student_id', $request->user()->id)->first();

        return ApiResponse::success($review ? self::review($review) : null);
    }

    public function saveMyReview(Request $request, string $course): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [], ['rating' => 'calificación', 'comment' => 'comentario']);

        return ApiResponse::success(self::review($this->community->review(Course::query()->findOrFail($course), $request->user(), $data)));
    }

    /* ───────────── Apoyo ───────────── */

    /**
     * @return array<string, mixed>
     */
    private static function announcement(CourseAnnouncement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'author' => $announcement->author ? trim("{$announcement->author->first_name} {$announcement->author->last_name}") : null,
            'emailed' => $announcement->emailed_at !== null,
            'created_at' => $announcement->created_at?->toIso8601String(),
            'updated_at' => $announcement->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function review(CourseReview $review): array
    {
        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'status' => $review->status,
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    private function viewable(Request $request, string $id): Course
    {
        $course = Course::query()->findOrFail($id);
        $this->access->assertCanView($request->user(), $course);

        return $course;
    }

    /**
     * Edita o borra quien lo escribió, o quien edita el contenido de todos los cursos.
     */
    private function editable(Request $request, string $course, string $id): CourseAnnouncement
    {
        $record = $this->viewable($request, $course);
        $announcement = CourseAnnouncement::query()->where('course_id', $record->id)->findOrFail($id);
        $user = $request->user();

        if ($announcement->author_id !== $user->id && ! $user->hasPermission('courses.content.write')) {
            throw new ApiException(ErrorCode::PermissionDenied, 'Solo quien escribió el anuncio o el administrador pueden cambiarlo.', 403);
        }

        return $announcement;
    }

    private function moderated(Request $request, string $course, string $id): CourseReview
    {
        $record = $this->viewable($request, $course);

        return CourseReview::query()->where('course_id', $record->id)->findOrFail($id);
    }

    private function enrolled(Request $request, string $course): void
    {
        $user = $request->user();
        $exists = $user instanceof User && Enrollment::query()->where('course_id', $course)->where('student_id', $user->id)->where('status', Enrollment::STATUS_ACTIVE)->exists();

        if (! $exists) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }
    }
}
