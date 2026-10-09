<?php

namespace App\Modules\Enrollments\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matrícula (RF-ENR-001, FP-05): solicitud → validar elegibilidad → crear →
 * habilitar acceso. La hace el personal a mano o el pago de una orden
 * (S5); las licencias (S8) crearán matrículas por este mismo servicio.
 */
class EnrollmentService
{
    /** Permiso que hace de un usuario alguien matriculable */
    public const STUDENT_PERMISSION = 'student.portal';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EnrollmentQuota $quota,
    ) {}

    /**
     * Usuarios activos que pueden ser alumnos y aún no tienen matrícula en el curso.
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
     * Si no se indica fin y el curso define días de acceso, la vigencia se
     * calcula desde el inicio (o desde hoy).
     *
     * @param  list<string>  $studentIds
     * @param  bool  $requirePublished  una compra ya pagada se matricula aunque el curso se haya archivado después
     * @return Collection<int, Enrollment> las matrículas creadas (quien ya estaba matriculado se omite)
     */
    public function enroll(
        Course $course,
        array $studentIds,
        User $actor,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        string $source = Enrollment::SOURCE_MANUAL,
        bool $requirePublished = true,
    ): Collection {
        if ($requirePublished && $course->status !== Course::STATUS_PUBLISHED) {
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

        if ($endsAt === null && $course->access_days !== null) {
            $endsAt = Carbon::instance($startsAt ?? now())->addDays($course->access_days)->endOfDay();
        }

        return DB::transaction(function () use ($course, $students, $actor, $startsAt, $endsAt, $source) {
            $already = Enrollment::query()
                ->where('course_id', $course->id)
                ->where('status', Enrollment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->pluck('student_id');
            $students = $students->reject(fn (User $student) => $already->contains($student->id));

            // Una compra ya pagada se matricula aunque el cupo se haya llenado después: el cupo se revisó al comprar
            if ($source !== Enrollment::SOURCE_PURCHASE) {
                $this->quota->assertRoomFor($students->pluck('id')->all());
            }

            return $students
                ->map(function (User $student) use ($course, $actor, $startsAt, $endsAt, $source) {
                    $enrollment = Enrollment::create([
                        'course_id' => $course->id,
                        'student_id' => $student->id,
                        'status' => Enrollment::STATUS_ACTIVE,
                        'source' => $source,
                        'enrolled_by' => $actor->id,
                        'enrolled_at' => now(),
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ]);

                    $this->audit->record('enrollments.created', $enrollment, null, [
                        'course' => $course->title,
                        'student' => $student->email,
                        'source' => $enrollment->source,
                        'ends_at' => $endsAt?->toDateString(),
                    ]);

                    return $enrollment;
                })
                ->values();
        });
    }

    /**
     * Cambia la vigencia de una matrícula: extenderla, acortarla o quitarle el límite.
     */
    public function updateValidity(Enrollment $enrollment, ?CarbonInterface $startsAt, ?CarbonInterface $endsAt): Enrollment
    {
        if (! $enrollment->isActive()) {
            throw new ApiException(ErrorCode::EnrollmentNotActive, 'La matrícula está cancelada.', 409);
        }

        return DB::transaction(function () use ($enrollment, $startsAt, $endsAt) {
            $before = ['starts_at' => $enrollment->starts_at?->toDateString(), 'ends_at' => $enrollment->ends_at?->toDateString()];

            $enrollment->update(['starts_at' => $startsAt, 'ends_at' => $endsAt]);

            $after = ['starts_at' => $startsAt?->toDateString(), 'ends_at' => $endsAt?->toDateString()];

            if ($before !== $after) {
                $this->audit->record('enrollments.updated', $enrollment, $before, $after + ['student' => $enrollment->student?->email]);
            }

            return $enrollment;
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
     * Matrícula del alumno que hoy le da acceso al curso (RN-003). Sin
     * matrícula, el curso no existe para él; con una fuera de vigencia, se
     * le dice por qué no puede entrar.
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

        match ($enrollment->state()) {
            Enrollment::STATE_EXPIRED => throw new ApiException(
                ErrorCode::EnrollmentExpired,
                'Tu acceso a este curso venció el '.$enrollment->ends_at->locale('es')->translatedFormat('j \d\e F \d\e Y').'. Comunícate con tu academia para extenderlo.',
                403,
                ['ends_at' => $enrollment->ends_at->toIso8601String()]
            ),
            Enrollment::STATE_UPCOMING => throw new ApiException(
                ErrorCode::EnrollmentNotStarted,
                'Tu acceso a este curso empieza el '.$enrollment->starts_at->locale('es')->translatedFormat('j \d\e F \d\e Y').'.',
                403,
                ['starts_at' => $enrollment->starts_at->toIso8601String()]
            ),
            default => null,
        };

        return $enrollment;
    }
}
