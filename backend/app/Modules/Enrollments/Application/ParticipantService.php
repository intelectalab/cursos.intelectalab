<?php

namespace App\Modules\Enrollments\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Application\RoleService;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Participantes de un curso: cada persona entra con un papel en ese curso,
 * alumno o docente. La misma persona puede ser docente en un curso y alumna
 * en otro, pero no las dos cosas en el mismo.
 */
class ParticipantService
{
    public const ROLE_STUDENT = 'STUDENT';

    public const ROLE_TEACHER = 'TEACHER';

    public const ROLES = [self::ROLE_STUDENT, self::ROLE_TEACHER];

    /** Permiso que da cada papel dentro del curso, y el rol base que lo otorga a quien no lo tiene */
    private const ACCESS = [
        self::ROLE_STUDENT => ['permission' => EnrollmentService::STUDENT_PERMISSION, 'base_role' => RoleCatalog::STUDENT],
        self::ROLE_TEACHER => ['permission' => 'courses.assigned.read', 'base_role' => RoleCatalog::TEACHER],
    ];

    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly RoleService $roles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Usuarios activos que aún no participan en el curso.
     *
     * @return Collection<int, User>
     */
    public function candidates(Course $course, ?string $search = null, int $limit = 100): Collection
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('id', $this->activeStudents($course))
            ->whereNotIn('id', DB::table('course_teachers')->where('course_id', $course->id)->select('user_id'))
            ->when($search, function ($query, $search) {
                $like = '%'.mb_strtolower($search).'%';

                $query->where(fn ($q) => $q
                    ->whereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like]));
            })
            ->with('roles')
            ->orderBy('first_name')->orderBy('last_name')
            ->limit($limit)
            ->get();
    }

    /**
     * Miembros activos de unos grupos que todavía no participan en el curso
     * (ni como alumnos ni como docentes): los demás simplemente no entran.
     *
     * @param  list<string>  $groupIds
     * @return list<string>
     */
    public function groupMembers(Course $course, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereIn('id', DB::table('group_members')->whereIn('group_id', $groupIds)->select('user_id'))
            ->whereNotIn('id', $this->activeStudents($course))
            ->whereNotIn('id', DB::table('course_teachers')->where('course_id', $course->id)->select('user_id'))
            ->pluck('id')
            ->all();
    }

    /**
     * Agrega personas al curso con un papel. A quien le falte el rol base de
     * ese papel (Alumno o Profesor) se le otorga, para que pueda entrar.
     *
     * @param  list<string>  $userIds
     * @return array{added: int, skipped: int, roles_granted: int}
     */
    public function add(
        Course $course,
        array $userIds,
        string $role,
        User $actor,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
    ): array {
        $users = User::query()->whereIn('id', $userIds)->where('status', User::STATUS_ACTIVE)->with('roles.permissions')->get();

        if ($users->count() !== count(array_unique($userIds))) {
            throw new ApiException(ErrorCode::ValidationError, 'Solo se puede agregar a usuarios activos de la organización.', 422);
        }

        // Nadie es alumno y docente del mismo curso
        $opposite = $role === self::ROLE_STUDENT
            ? $course->teachers()->whereIn('users.id', $userIds)->get()
            : User::query()->whereIn('id', $userIds)->whereIn('id', $this->activeStudents($course))->get();

        if ($opposite->isNotEmpty()) {
            $as = $role === self::ROLE_STUDENT ? 'docente' : 'alumno';

            throw new ApiException(
                ErrorCode::ValidationError,
                "Hay personas que ya participan en este curso como {$as}. Quítalas primero de ese papel.",
                422,
                ['user_ids' => $opposite->map(fn (User $user) => "{$user->email} ya es {$as} de este curso.")->values()->all()]
            );
        }

        return DB::transaction(function () use ($course, $users, $role, $actor, $startsAt, $endsAt) {
            $granted = $users
                ->reject(fn (User $user) => $user->hasPermission(self::ACCESS[$role]['permission']))
                ->each(fn (User $user) => $this->roles->grantBaseRole($user, self::ACCESS[$role]['base_role']))
                ->count();

            if ($role === self::ROLE_STUDENT) {
                // Recargados: el rol recién otorgado ya cuenta para la matrícula
                $added = $this->enrollments->enroll($course, $users->pluck('id')->all(), $actor, $startsAt, $endsAt)->count();
            } else {
                $before = $course->teachers()->pluck('users.id')->sort()->values()->all();
                $added = count($course->teachers()->syncWithoutDetaching($users->pluck('id')->all())['attached']);
                $after = $course->teachers()->pluck('users.id')->sort()->values()->all();

                if ($before !== $after) {
                    $this->audit->record('courses.teachers_updated', $course, ['teachers' => $before], ['teachers' => $after]);
                }
            }

            return ['added' => $added, 'skipped' => $users->count() - $added, 'roles_granted' => $granted];
        });
    }

    /**
     * Quita a un docente del curso. Conserva su rol: puede dictar otros cursos.
     */
    public function removeTeacher(Course $course, User $teacher): void
    {
        $before = $course->teachers()->pluck('users.id')->sort()->values()->all();

        if ($course->teachers()->detach($teacher->id) === 0) {
            throw new ApiException(ErrorCode::NotFound, 'Esa persona no es docente de este curso.', 404);
        }

        $this->audit->record('courses.teachers_updated', $course, ['teachers' => $before], [
            'teachers' => array_values(array_diff($before, [$teacher->id])),
        ]);
    }

    private function activeStudents(Course $course): Builder
    {
        return Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->select('student_id');
    }
}
