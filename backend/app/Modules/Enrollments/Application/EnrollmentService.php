<?php

namespace App\Modules\Enrollments\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matrícula manual: el personal de la organización inscribe alumnos en un
 * curso publicado. La compra (S5) y las licencias (S8) crearán matrículas
 * por este mismo servicio.
 */
class EnrollmentService
{
    /** Permiso que hace de un usuario alguien matriculable */
    public const STUDENT_PERMISSION = 'student.portal';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Usuarios activos que pueden ser alumnos y aún no tienen matrícula activa en el curso.
     *
     * @return Collection<int, User>
     */
    public function candidates(Course $course, ?string $search = null): Collection
    {
        $enrolled = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->select('student_id');

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('id', $enrolled)
            ->when($search, function ($query, $search) {
                $like = '%'.mb_strtolower($search).'%';

                $query->where(fn ($q) => $q
                    ->whereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like]));
            })
            ->with('roles.permissions')
            ->orderBy('first_name')->orderBy('last_name')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission(self::STUDENT_PERMISSION))
            ->values();
    }

    /**
     * @param  list<string>  $studentIds
     * @return Collection<int, Enrollment> las matrículas creadas (quien ya estaba matriculado se omite)
     */
    public function enroll(Course $course, array $studentIds, User $actor): Collection
    {
        if ($course->status !== Course::STATUS_PUBLISHED) {
            throw new ApiException(
                ErrorCode::CourseInvalidState,
                'Solo se puede matricular en un curso publicado.',
                409
            );
        }

        $students = User::query()->whereIn('id', $studentIds)->with('roles.permissions')->get();
        $notStudents = $students->reject(fn (User $user) => $user->isActive() && $user->hasPermission(self::STUDENT_PERMISSION));

        if ($notStudents->isNotEmpty() || $students->count() !== count(array_unique($studentIds))) {
            throw new ApiException(
                ErrorCode::ValidationError,
                'Solo se puede matricular a usuarios activos con acceso al portal del alumno.',
                422,
                ['student_ids' => $notStudents->map(fn (User $user) => "{$user->email} no tiene un rol de alumno activo.")->values()->all()]
            );
        }

        return DB::transaction(function () use ($course, $students, $actor) {
            $already = Enrollment::query()
                ->where('course_id', $course->id)
                ->where('status', Enrollment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->pluck('student_id');

            return $students
                ->reject(fn (User $student) => $already->contains($student->id))
                ->map(function (User $student) use ($course, $actor) {
                    $enrollment = Enrollment::create([
                        'course_id' => $course->id,
                        'student_id' => $student->id,
                        'status' => Enrollment::STATUS_ACTIVE,
                        'source' => Enrollment::SOURCE_MANUAL,
                        'enrolled_by' => $actor->id,
                        'enrolled_at' => now(),
                    ]);

                    $this->audit->record('enrollments.created', $enrollment, null, [
                        'course' => $course->title,
                        'student' => $student->email,
                        'source' => $enrollment->source,
                    ]);

                    return $enrollment;
                })
                ->values();
        });
    }

    public function cancel(Enrollment $enrollment): Enrollment
    {
        if (! $enrollment->isActive()) {
            throw new ApiException(ErrorCode::EnrollmentNotActive, 'La matrícula ya no está activa.', 409);
        }

        return DB::transaction(function () use ($enrollment) {
            $enrollment->update(['status' => Enrollment::STATUS_CANCELLED, 'cancelled_at' => now()]);

            $this->audit->record(
                'enrollments.cancelled',
                $enrollment,
                ['status' => Enrollment::STATUS_ACTIVE],
                ['status' => Enrollment::STATUS_CANCELLED, 'student' => $enrollment->student?->email]
            );

            return $enrollment;
        });
    }

    /**
     * Matrícula activa del alumno en el curso; si no la tiene, el curso no existe para él.
     */
    public function activeFor(User $student, string $courseId): Enrollment
    {
        $enrollment = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('course_id', $courseId)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->with('course.currentVersion')
            ->first();

        // Sin versión publicada no hay nada que mostrar
        if (! $enrollment || ! $enrollment->course->currentVersion) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }

        return $enrollment;
    }
}
