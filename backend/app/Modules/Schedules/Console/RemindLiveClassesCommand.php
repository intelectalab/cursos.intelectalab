<?php

namespace App\Modules\Schedules\Console;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Schedules\Application\NoClassDays;
use App\Modules\Schedules\Domain\Models\TeacherSchedule;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Recordatorio a los alumnos el día de su clase en vivo, a las 7 de la mañana
 * de cada academia: la tarea corre cada hora y solo avisa en las academias
 * donde ya son las 7. No sale los feriados de la academia ni los días sin clase del
 * docente. El correo se puede editar o apagar en Configuración → Correos.
 */
class RemindLiveClassesCommand extends Command
{
    protected $signature = 'live-classes:remind';

    protected $description = 'Avisa a los alumnos de las clases en vivo que tienen hoy';

    /** Hora local de la academia a la que sale el recordatorio */
    public const SEND_HOUR = 7;

    public function handle(TenantContext $context, TenantNotifier $notifier, TenantSettingsService $settings): int
    {
        $sent = 0;

        foreach (Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->pluck('id') as $tenantId) {
            $sent += $context->runForTenant($tenantId, function () use ($notifier, $settings) {
                // El día y la hora son los de la academia, no los del servidor
                $now = CarbonImmutable::now($settings->timezone());
                if ($now->hour !== self::SEND_HOUR) {
                    return 0;
                }

                return $this->remind($notifier, $now->toDateString());
            });
        }

        $this->info("Recordatorios de clases en vivo: {$sent}");

        return self::SUCCESS;
    }

    private function remind(TenantNotifier $notifier, string $today): int
    {
        $weekday = CarbonImmutable::parse($today)->dayOfWeekIso;
        $schedules = TeacherSchedule::query()
            ->whereNotNull('confirmed_blocks')
            ->where('confirmed_valid_from', '<=', $today)
            ->with('teacher:id,first_name,last_name,status')
            ->get()
            ->filter(fn (TeacherSchedule $schedule) => $schedule->teacher?->status === User::STATUS_ACTIVE);
        $noClass = NoClassDays::between($schedules->pluck('teacher_id'), $today, $today);
        $sent = 0;

        foreach ($schedules as $schedule) {
            if ($noClass->blocks($schedule->teacher_id, $today)) {
                continue;
            }

            foreach ($schedule->confirmed_blocks as $block) {
                if ($block['type'] !== TeacherSchedule::TYPE_LIVE_CLASS || $block['day'] !== $weekday || empty($block['course_id'])) {
                    continue;
                }

                // Solo si quien la dicta sigue siendo docente del curso
                $course = Course::query()->whereKey($block['course_id'])->whereHas('teachers', fn ($teachers) => $teachers->whereKey($schedule->teacher_id))->first();
                if (! $course) {
                    continue;
                }

                $students = User::query()->whereIn('id', Enrollment::query()->where('course_id', $course->id)->accessible()->select('student_id'))->get();

                foreach ($students as $student) {
                    $notifier->notify($student, 'clase_en_vivo_hoy', [
                        'curso' => $course->title,
                        'hora' => $block['start'],
                        'docente' => trim("{$schedule->teacher->first_name} {$schedule->teacher->last_name}"),
                        'enlace' => $block['meeting_url'] ?? null,
                    ], "/my-courses/{$course->id}");
                    $sent++;
                }
            }
        }

        return $sent;
    }
}
