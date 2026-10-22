<?php

namespace App\Modules\Schedules\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Schedules\Domain\Models\ScheduleException;
use App\Modules\Schedules\Domain\Models\TeacherSchedule;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Horarios de los docentes: el personal arma la semana tipo y la envía; el
 * docente la confirma o la observa con un comentario. Cualquier cambio
 * vuelve a pedir su confirmación. Los alumnos ven las clases en vivo de sus
 * cursos según lo último que el docente confirmó.
 */
class ScheduleService
{
    public const DAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public const TYPE_NAMES = [
        TeacherSchedule::TYPE_AVAILABLE => 'Disponible',
        TeacherSchedule::TYPE_LIVE_CLASS => 'Clase en vivo',
        TeacherSchedule::TYPE_GRADING => 'Revisión de trabajos',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantNotifier $notifier,
        private readonly TenantSettingsService $settings,
    ) {}

    /**
     * Cursos en los que el docente puede dar una clase en vivo: los suyos, sin los archivados.
     *
     * @return Collection<int, Course>
     */
    public function coursesOf(User $teacher)
    {
        return Course::query()
            ->whereHas('teachers', fn ($teachers) => $teachers->whereKey($teacher->id))
            ->where('status', '!=', Course::STATUS_ARCHIVED)
            ->orderBy('title')
            ->get(['id', 'title', 'status']);
    }

    /**
     * Guarda la semana tipo y la envía al docente. Si no cambió nada respecto
     * de lo que ya confirmó, se queda confirmada.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function send(User $teacher, string $validFrom, array $blocks, User $actor): TeacherSchedule
    {
        $blocks = $this->normalize($teacher, $blocks);

        $schedule = DB::transaction(function () use ($teacher, $validFrom, $blocks, $actor) {
            $schedule = TeacherSchedule::query()->where('teacher_id', $teacher->id)->lockForUpdate()->first();
            $before = $schedule ? $this->snapshot($schedule) : null;

            $unchanged = $schedule
                && $schedule->status === TeacherSchedule::STATUS_CONFIRMED
                // jsonb reordena las claves de cada bloque: se comparan los valores, no el orden
                && $schedule->confirmed_blocks == $blocks
                && $schedule->confirmed_valid_from?->toDateString() === $validFrom;

            if ($unchanged) {
                return null;
            }

            $schedule ??= new TeacherSchedule(['teacher_id' => $teacher->id]);
            $schedule->fill([
                'valid_from' => $validFrom,
                'blocks' => $blocks,
                'status' => TeacherSchedule::STATUS_PENDING,
                'observation' => null,
                'sent_at' => now(),
                'sent_by' => $actor->id,
                'responded_at' => null,
            ])->save();

            $this->audit->record('schedules.sent', $schedule, $before, $this->snapshot($schedule));

            return $schedule;
        });

        if ($schedule === null) {
            return TeacherSchedule::query()->where('teacher_id', $teacher->id)->firstOrFail();
        }

        $this->notifier->notify($teacher, 'horario_por_confirmar', [
            'desde' => $schedule->valid_from->format('d/m/Y'),
            'enviado_por' => trim("{$actor->first_name} {$actor->last_name}"),
        ], '/my-schedule');

        return $schedule;
    }

    /**
     * El docente acepta el horario que le enviaron: pasa a ser el vigente para los alumnos.
     */
    public function confirm(User $teacher): TeacherSchedule
    {
        return DB::transaction(function () use ($teacher) {
            $schedule = $this->pendingFor($teacher);

            $schedule->update([
                'status' => TeacherSchedule::STATUS_CONFIRMED,
                'observation' => null,
                'responded_at' => now(),
                'confirmed_valid_from' => $schedule->valid_from,
                'confirmed_blocks' => $schedule->blocks,
            ]);

            $this->audit->record('schedules.confirmed', $schedule, ['status' => TeacherSchedule::STATUS_PENDING], ['status' => TeacherSchedule::STATUS_CONFIRMED]);

            return $schedule;
        });
    }

    /**
     * El docente no está de acuerdo y explica qué cambiar. Lo confirmado antes sigue vigente.
     */
    public function observe(User $teacher, string $note): TeacherSchedule
    {
        $schedule = DB::transaction(function () use ($teacher, $note) {
            $schedule = $this->pendingFor($teacher);

            $schedule->update([
                'status' => TeacherSchedule::STATUS_OBSERVED,
                'observation' => $note,
                'responded_at' => now(),
            ]);

            $this->audit->record('schedules.observed', $schedule, ['status' => TeacherSchedule::STATUS_PENDING], ['status' => TeacherSchedule::STATUS_OBSERVED, 'observation' => $note]);

            return $schedule;
        });

        $this->notifier->notify($schedule->sender, 'horario_observado', [
            'docente' => trim("{$teacher->first_name} {$teacher->last_name}"),
            'observacion' => $note,
        ], "/schedules/{$teacher->id}");

        return $schedule;
    }

    /**
     * Clases en vivo confirmadas de los cursos a los que el alumno tiene acceso.
     *
     * @return list<array<string, mixed>>
     */
    public function liveClassesFor(User $student): array
    {
        $courses = Course::query()
            ->whereIn('id', Enrollment::query()->where('student_id', $student->id)->accessible()->select('course_id'))
            ->with('teachers:id')
            ->get(['id', 'title'])
            ->keyBy('id');

        if ($courses->isEmpty()) {
            return [];
        }

        $teacherIds = $courses->flatMap(fn (Course $course) => $course->teachers->pluck('id'))->unique();

        $classes = [];

        TeacherSchedule::query()
            ->whereIn('teacher_id', $teacherIds)
            ->whereNotNull('confirmed_blocks')
            ->with('teacher:id,first_name,last_name')
            ->get()
            ->each(function (TeacherSchedule $schedule) use ($courses, &$classes) {
                foreach ($schedule->confirmed_blocks as $block) {
                    $course = $courses->get($block['course_id'] ?? '');

                    // Solo las clases de un curso suyo que ese docente sigue dictando
                    if ($block['type'] !== TeacherSchedule::TYPE_LIVE_CLASS || ! $course || ! $course->teachers->contains('id', $schedule->teacher_id)) {
                        continue;
                    }

                    $classes[] = [
                        'course_id' => $course->id,
                        'course_title' => $course->title,
                        'teacher' => trim("{$schedule->teacher->first_name} {$schedule->teacher->last_name}"),
                        'day' => $block['day'],
                        'start' => $block['start'],
                        'end' => $block['end'],
                        'meeting_url' => $block['meeting_url'] ?? null,
                        'note' => $block['note'] ?? null,
                        'valid_from' => $schedule->confirmed_valid_from?->toDateString(),
                        // Próximos días de esta clase en que no habrá clase (feriado o permiso del docente)
                        'cancelled' => $this->cancelledDays($schedule->teacher_id, (int) $block['day']),
                    ];
                }
            });

        usort($classes, fn (array $a, array $b) => [$a['day'], $a['start']] <=> [$b['day'], $b['start']]);

        return $classes;
    }

    /**
     * Las próximas fechas (4 semanas) de ese día de la semana en que el docente no tiene clase.
     *
     * @return list<array{date: string, reason: ?string}>
     */
    private function cancelledDays(string $teacherId, int $weekday): array
    {
        $today = CarbonImmutable::now($this->settings->timezone())->startOfDay();
        $until = $today->addWeeks(4);
        $noClass = NoClassDays::between([$teacherId], $today->toDateString(), $until->toDateString());
        $days = [];

        for ($date = $today; $date->lte($until); $date = $date->addDay()) {
            if ($date->dayOfWeekIso === $weekday && ($reason = $noClass->reason($teacherId, $date->toDateString())) !== null) {
                $days[] = ['date' => $date->toDateString(), 'reason' => $reason];
            }
        }

        return $days;
    }

    /**
     * Días sin clase de un docente, del más próximo al más lejano.
     *
     * @return list<array{id: string, date: string, reason: ?string}>
     */
    public function exceptionsOf(User $teacher): array
    {
        return ScheduleException::query()
            ->where('teacher_id', $teacher->id)
            ->where('date', '>=', CarbonImmutable::now($this->settings->timezone())->subDays(30)->toDateString())
            ->orderBy('date')
            ->get()
            ->map(fn (ScheduleException $exception) => ['id' => $exception->id, 'date' => $exception->date->toDateString(), 'reason' => $exception->reason])
            ->all();
    }

    public function addException(User $teacher, string $date, ?string $reason, User $actor): ScheduleException
    {
        $exception = ScheduleException::query()->firstOrNew(['teacher_id' => $teacher->id, 'date' => $date]);
        $exception->fill(['reason' => filled($reason) ? trim($reason) : null, 'created_by' => $actor->id])->save();
        $this->audit->record('schedules.exception_added', $exception, null, ['teacher' => trim("{$teacher->first_name} {$teacher->last_name}"), 'date' => $date]);

        return $exception;
    }

    public function removeException(User $teacher, string $id): void
    {
        $exception = ScheduleException::query()->where('teacher_id', $teacher->id)->findOrFail($id);
        $this->audit->record('schedules.exception_removed', $exception, ['date' => $exception->date->toDateString()], null);
        $exception->delete();
    }

    /**
     * Ordena y valida los bloques: horas coherentes, sin choques en el mismo
     * día y, en las clases en vivo, un curso que el docente dicta.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{day: int, start: string, end: string, type: string, course_id: ?string, meeting_url: ?string, note: ?string}>
     */
    private function normalize(User $teacher, array $blocks): array
    {
        $courses = $this->coursesOf($teacher)->pluck('id')->all();

        $normalized = array_map(function (array $block, int $index) use ($courses) {
            $live = $block['type'] === TeacherSchedule::TYPE_LIVE_CLASS;

            if ($block['start'] >= $block['end']) {
                throw ValidationException::withMessages(["blocks.{$index}.end" => 'La hora de fin debe ser posterior a la de inicio.']);
            }

            if ($live && ! empty($block['course_id']) && ! in_array($block['course_id'], $courses, true)) {
                throw ValidationException::withMessages(["blocks.{$index}.course_id" => 'El docente no dicta ese curso. Asígnalo primero desde Participantes del curso.']);
            }

            return [
                'day' => (int) $block['day'],
                'start' => $block['start'],
                'end' => $block['end'],
                'type' => $block['type'],
                // El curso y el enlace solo tienen sentido en una clase en vivo
                'course_id' => $live ? ($block['course_id'] ?? null) : null,
                'meeting_url' => $live ? (trim((string) ($block['meeting_url'] ?? '')) ?: null) : null,
                'note' => trim((string) ($block['note'] ?? '')) ?: null,
            ];
        }, $blocks, array_keys($blocks));

        usort($normalized, fn (array $a, array $b) => [$a['day'], $a['start']] <=> [$b['day'], $b['start']]);

        for ($i = 1; $i < count($normalized); $i++) {
            $previous = $normalized[$i - 1];
            $current = $normalized[$i];

            if ($previous['day'] === $current['day'] && $current['start'] < $previous['end']) {
                throw new ApiException(
                    ErrorCode::ValidationError,
                    sprintf('Dos bloques se cruzan el %s: %s–%s y %s–%s.', mb_strtolower(self::DAYS[$current['day']]), $previous['start'], $previous['end'], $current['start'], $current['end']),
                    422,
                );
            }
        }

        return $normalized;
    }

    private function pendingFor(User $teacher): TeacherSchedule
    {
        $schedule = TeacherSchedule::query()->where('teacher_id', $teacher->id)->with('sender')->lockForUpdate()->first();

        if (! $schedule) {
            throw new ApiException(ErrorCode::NotFound, 'Todavía no tienes un horario.', 404);
        }

        if ($schedule->status !== TeacherSchedule::STATUS_PENDING) {
            throw new ApiException(ErrorCode::Conflict, 'Este horario ya fue respondido. Espera a que te envíen uno nuevo.', 409);
        }

        return $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TeacherSchedule $schedule): array
    {
        return [
            'status' => $schedule->status,
            'valid_from' => $schedule->valid_from?->toDateString(),
            'blocks' => count($schedule->blocks ?? []),
        ];
    }
}
