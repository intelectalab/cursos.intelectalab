<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Cursos disponibles" del portal del alumno (RF-STU-001): los cursos
 * publicados en los que aún no está matriculado. Solo la ficha: el
 * contenido exige matrícula (RN-003) y la compra llega con S5.
 */
class AvailableCoursesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courses = $this->query($request->user())->orderBy('title')->get();

        return ApiResponse::success($courses->map(fn (Course $course) => $this->card($course))->values());
    }

    public function show(Request $request, string $course): JsonResponse
    {
        $record = $this->query($request->user())->whereKey($course)->first();

        if (! $record) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }

        $snapshot = $record->currentVersion->snapshot;

        return ApiResponse::success($this->card($record) + [
            'description' => $snapshot['description'] ?? null,
            'access_days' => $record->access_days,
            'teachers' => $record->teachers->map(fn (User $teacher) => trim("{$teacher->first_name} {$teacher->last_name}"))->values(),
            // Temario: títulos y tipos, sin el contenido
            'sections' => array_map(fn (array $section) => [
                'title' => $section['title'],
                'items' => array_merge(
                    array_map(fn (array $resource) => ['kind' => 'RESOURCE', 'type' => $resource['type'], 'title' => $resource['title']], $section['resources'] ?? []),
                    array_map(fn (array $activity) => ['kind' => 'ACTIVITY', 'type' => $activity['type'], 'title' => $activity['title']], $section['activities'] ?? []),
                ),
            ], $snapshot['sections'] ?? []),
        ]);
    }

    /**
     * Cursos publicados sin matrícula vigente del alumno (una cancelada no cuenta).
     *
     * @return Builder<Course>
     */
    private function query(User $student): Builder
    {
        return Course::query()
            ->where('status', Course::STATUS_PUBLISHED)
            ->whereNotNull('current_version_id')
            ->whereNotIn('id', Enrollment::query()
                ->where('student_id', $student->id)
                ->where('status', Enrollment::STATUS_ACTIVE)
                ->select('course_id'))
            ->with(['currentVersion', 'teachers']);
    }

    /**
     * Datos de la versión publicada, no del borrador.
     *
     * @return array<string, mixed>
     */
    private function card(Course $course): array
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
        ];
    }
}
