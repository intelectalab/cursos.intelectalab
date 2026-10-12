<?php

namespace App\Modules\Reports\Application;

use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseTeacher;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Docentes de la academia (YC-PRO-001) y la ficha de cada uno (YC-PRO-002):
 * los cursos que tiene asignados y su carga de calificación, es decir, las
 * entregas de tareas que esperan revisión en esos cursos.
 */
class TeacherDirectory
{
    public const FILTER_WITH_COURSES = 'WITH_COURSES';

    public const FILTER_WITHOUT_COURSES = 'WITHOUT_COURSES';

    public const FILTER_INACTIVE = 'INACTIVE';

    public const FILTERS = [self::FILTER_WITH_COURSES, self::FILTER_WITHOUT_COURSES, self::FILTER_INACTIVE];

    /** Ventana de "calificadas hace poco" de la ficha */
    public const RECENT_DAYS = 30;

    /**
     * Es docente quien tiene el rol Docente o está asignado a algún curso.
     * Los cursos archivados no cuentan como carga.
     *
     * @return Builder<User>
     */
    public function query(?string $search = null): Builder
    {
        return User::query()
            ->select('users.*')
            ->selectSub($this->assignments()->selectRaw('count(*)'), 'courses_count')
            ->selectSub(
                TaskSubmission::query()
                    ->selectRaw('count(*)')
                    ->where('status', TaskSubmission::STATUS_SUBMITTED)
                    ->whereIn('course_id', $this->assignments()->select('course_id')),
                'pending_submissions'
            )
            ->where(fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $roles) => $roles->where('code', RoleCatalog::TEACHER))
                ->orWhereIn('users.id', CourseTeacher::query()->select('user_id')))
            ->when($search, function (Builder $query, string $search) {
                $like = '%'.mb_strtolower($search).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw('lower(users.email) like ?', [$like])
                    ->orWhereRaw('lower(users.first_name) like ?', [$like])
                    ->orWhereRaw('lower(users.last_name) like ?', [$like]));
            });
    }

    /**
     * @param  Builder<User>  $query
     */
    public function filter(Builder $query, string $filter): void
    {
        $withCourses = CourseTeacher::query()->select('user_id')->whereIn('course_id', $this->openCourses());

        match ($filter) {
            self::FILTER_WITH_COURSES => $query->whereIn('users.id', $withCourses),
            self::FILTER_WITHOUT_COURSES => $query->whereNotIn('users.id', $withCourses),
            self::FILTER_INACTIVE => $query->where('users.status', User::STATUS_INACTIVE),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function row(User $teacher): array
    {
        return [
            'id' => $teacher->id,
            'name' => trim("{$teacher->first_name} {$teacher->last_name}"),
            'email' => $teacher->email,
            'phone' => $teacher->phone,
            'status' => $teacher->status,
            'last_login_at' => $teacher->last_login_at?->toIso8601String(),
            'created_at' => $teacher->created_at->toIso8601String(),
            'courses_count' => (int) $teacher->courses_count,
            'pending_submissions' => (int) $teacher->pending_submissions,
        ];
    }

    /**
     * Ficha del docente: sus cursos con alumnos y entregas por revisar.
     *
     * @return array<string, mixed>
     */
    public function profile(User $teacher): array
    {
        $courses = Course::query()
            ->whereIn('id', CourseTeacher::query()->select('course_id')->where('user_id', $teacher->id))
            ->with('currentVersion')
            ->orderBy('title')
            ->get();
        $ids = $courses->pluck('id');

        $students = Enrollment::query()
            ->whereIn('course_id', $ids)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->selectRaw('course_id, count(*) as total')
            ->groupBy('course_id')
            ->pluck('total', 'course_id');
        $pending = TaskSubmission::query()
            ->whereIn('course_id', $ids)
            ->where('status', TaskSubmission::STATUS_SUBMITTED)
            ->selectRaw('course_id, count(*) as total, min(submitted_at) as oldest')
            ->groupBy('course_id')
            ->get()
            ->keyBy('course_id');

        return [
            'teacher' => $this->row($teacher),
            'courses' => $courses->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'status' => $course->status,
                'version' => $course->currentVersion?->number,
                'students' => (int) ($students[$course->id] ?? 0),
                'pending_submissions' => (int) ($pending[$course->id]->total ?? 0),
                // Desde cuándo espera la entrega más antigua sin revisar
                'oldest_pending_at' => isset($pending[$course->id]) ? now()->parse($pending[$course->id]->oldest)->toIso8601String() : null,
            ])->values(),
            'students_total' => (int) $students->only($courses->where('status', '!=', Course::STATUS_ARCHIVED)->pluck('id'))->sum(),
            // Lo que calificó esta persona, en cualquier curso
            'graded_recently' => TaskSubmission::query()
                ->where('graded_by', $teacher->id)
                ->where('graded_at', '>=', now()->subDays(self::RECENT_DAYS))
                ->count(),
        ];
    }

    /**
     * Asignaciones del docente de la fila a cursos no archivados.
     *
     * @return Builder<CourseTeacher>
     */
    private function assignments(): Builder
    {
        return CourseTeacher::query()
            ->whereColumn('course_teachers.user_id', 'users.id')
            ->whereIn('course_teachers.course_id', $this->openCourses());
    }

    /**
     * @return Builder<Course>
     */
    private function openCourses(): Builder
    {
        return Course::query()->select('id')->where('status', '!=', Course::STATUS_ARCHIVED);
    }
}
