<?php

namespace App\Modules\Schedules\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Certificates\Application\CertificateService;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Schedules\Domain\Models\LiveClassAttendance;
use App\Modules\Schedules\Domain\Models\TeacherSchedule;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asistencia a las clases en vivo. Las clases de un día salen del horario
 * que confirmó cada docente del curso; quien hace el seguimiento del curso
 * marca a cada alumno como presente, tarde, ausente o justificado.
 */
class AttendanceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantSettingsService $settings,
        private readonly CertificateService $certificates,
    ) {}

    public function today(): string
    {
        return CarbonImmutable::now($this->settings->timezone())->toDateString();
    }

    /**
     * Clases en vivo del curso ese día, según los horarios confirmados.
     *
     * @return list<array{start: string, end: string, teacher: string}>
     */
    public function sessionsOn(Course $course, string $date): array
    {
        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;

        $sessions = [];
        $schedules = TeacherSchedule::query()
            ->whereIn('teacher_id', $course->teachers()->select('users.id'))
            ->whereNotNull('confirmed_blocks')
            ->where('confirmed_valid_from', '<=', $date)
            ->with('teacher:id,first_name,last_name')
            ->get();
        $noClass = NoClassDays::between($schedules->pluck('teacher_id'), $date, $date);

        $schedules
            ->reject(fn (TeacherSchedule $schedule) => $noClass->blocks($schedule->teacher_id, $date))
            ->each(function (TeacherSchedule $schedule) use ($course, $weekday, &$sessions) {
                foreach ($schedule->confirmed_blocks as $block) {
                    if ($block['type'] === TeacherSchedule::TYPE_LIVE_CLASS && ($block['course_id'] ?? null) === $course->id && $block['day'] === $weekday) {
                        $sessions[] = [
                            'start' => $block['start'],
                            'end' => $block['end'],
                            'teacher' => trim("{$schedule->teacher->first_name} {$schedule->teacher->last_name}"),
                        ];
                    }
                }
            });

        usort($sessions, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $sessions;
    }

    /**
     * Alumnos que pasan lista: los matriculados del curso que no fueron dados de baja.
     *
     * @return Collection<int, User>
     */
    public function roster(Course $course)
    {
        return User::query()
            ->whereIn('id', Enrollment::query()->where('course_id', $course->id)->where('status', Enrollment::STATUS_ACTIVE)->select('student_id'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email']);
    }

    /**
     * Guarda la asistencia de una clase. Solo de una clase del horario y de un día que ya llegó.
     *
     * @param  list<array{student_id: string, status: string}>  $records
     */
    public function record(Course $course, string $date, string $start, array $records, User $actor): int
    {
        if ($date > $this->today()) {
            throw new ApiException(ErrorCode::ValidationError, 'Todavía no llega ese día: la asistencia se toma el día de la clase o después.', 422);
        }

        if (! collect($this->sessionsOn($course, $date))->contains('start', $start)) {
            throw new ApiException(ErrorCode::ValidationError, 'Ese día no hay una clase en vivo de este curso a esa hora en el horario confirmado.', 422);
        }

        $roster = $this->roster($course)->pluck('id')->all();
        $foreign = collect($records)->pluck('student_id')->diff($roster);

        if ($foreign->isNotEmpty()) {
            throw new ApiException(ErrorCode::ValidationError, 'Hay personas que no están matriculadas en este curso.', 422);
        }

        DB::transaction(function () use ($course, $date, $start, $records, $actor) {
            foreach ($records as $record) {
                LiveClassAttendance::query()->updateOrCreate(
                    ['course_id' => $course->id, 'session_date' => $date, 'session_start' => $start, 'student_id' => $record['student_id']],
                    ['status' => $record['status'], 'recorded_by' => $actor->id],
                );
            }

            $this->audit->record('attendance.recorded', $course, null, [
                'date' => $date,
                'start' => $start,
                'students' => count($records),
                'present' => collect($records)->whereIn('status', [LiveClassAttendance::STATUS_PRESENT, LiveClassAttendance::STATUS_LATE])->count(),
            ]);
        });

        // Con la asistencia al día, quien ya completó el curso y ahora cumple recibe su certificado
        if ($course->issues_certificate && $course->min_attendance_percent !== null) {
            User::query()->whereIn('id', collect($records)->pluck('student_id'))->get()->each(fn (User $student) => $this->certificates->syncFor($student));
        }

        return count($records);
    }

    /**
     * Porcentaje de asistencia: presentes y tardanzas sobre las clases con
     * asistencia tomada, sin contar las faltas justificadas.
     *
     * @param  iterable<LiveClassAttendance>  $records
     * @return array{sessions: int, attended: int, absent: int, excused: int, percent: ?int}
     */
    public static function tally(iterable $records): array
    {
        $counts = collect($records)->countBy('status');
        $attended = ($counts[LiveClassAttendance::STATUS_PRESENT] ?? 0) + ($counts[LiveClassAttendance::STATUS_LATE] ?? 0);
        $absent = $counts[LiveClassAttendance::STATUS_ABSENT] ?? 0;
        $counted = $attended + $absent;

        return [
            'sessions' => $counts->sum(),
            'attended' => $attended,
            'absent' => $absent,
            'excused' => $counts[LiveClassAttendance::STATUS_EXCUSED] ?? 0,
            'percent' => $counted === 0 ? null : (int) round($attended * 100 / $counted),
        ];
    }
}
