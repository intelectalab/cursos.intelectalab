<?php

namespace App\Modules\Courses\Application;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance por curso. Los permisos "assigned" (rol Profesor) solo valen
 * sobre los cursos donde el usuario está asignado como profesor.
 */
class CourseAccess
{
    public function seesAll(User $user): bool
    {
        return $user->hasPermission('courses.read');
    }

    /**
     * Acota un listado a los cursos que el usuario puede ver.
     *
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->seesAll($user)) {
            return $query;
        }

        return $query->whereHas('teachers', fn ($teachers) => $teachers->whereKey($user->id));
    }

    public function canView(User $user, Course $course): bool
    {
        return $this->seesAll($user)
            || ($user->hasPermission('courses.assigned.read') && $this->teaches($user, $course));
    }

    public function canEditContent(User $user, Course $course): bool
    {
        return $user->hasPermission('courses.content.write')
            || ($user->hasPermission('content.assigned.write') && $this->teaches($user, $course));
    }

    /**
     * Un curso ajeno responde igual que uno inexistente.
     */
    public function assertCanView(User $user, Course $course): void
    {
        if (! $this->canView($user, $course)) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }
    }

    public function assertCanEditContent(User $user, Course $course): void
    {
        $this->assertCanView($user, $course);

        if (! $this->canEditContent($user, $course)) {
            throw new ApiException(
                ErrorCode::PermissionDenied,
                'No cuenta con el permiso requerido para editar el contenido de este curso.',
                403,
                ['permission' => 'courses.content.write']
            );
        }
    }

    private function teaches(User $user, Course $course): bool
    {
        return $course->teachers()->whereKey($user->id)->exists();
    }
}
