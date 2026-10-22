<?php

namespace App\Modules\Reports\Application;

use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Claims\Domain\Models\Claim;
use App\Modules\Cms\Domain\Models\Page;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\PaymentAttempt;
use App\Modules\Sales\Http\OrderPresenter;
use App\Modules\Schedules\Domain\Models\TeacherSchedule;
use App\Modules\Support\Domain\Models\Ticket;

/**
 * Lo que espera a una persona del personal, en cifras: la campana de
 * avisos, los pendientes de Inicio y el asistente. Cada cifra solo se
 * calcula si la persona puede atender ese tema; las demás llegan en null.
 */
class PendingSummary
{
    /**
     * @return array<string, int|null>
     */
    public function for(User $user): array
    {
        $can = fn (string ...$permissions) => collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission));

        return [
            // Cobros que una pasarela hizo y no se aplicaron a su orden
            'payment_reviews' => $can('payments.confirm', 'orders.read')
                ? PaymentAttempt::query()->where('status', PaymentAttempt::STATUS_NEEDS_REVIEW)->count()
                : null,
            // Consultas abiertas en las que lo último lo escribió quien consulta
            'tickets_waiting' => $can('tickets.read')
                ? Ticket::query()->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS])->where('last_message_by', Ticket::BY_REQUESTER)->count()
                : null,
            // Páginas que alguien envió a revisión y esperan a quien publica
            'pages_in_review' => $can('cms.publish')
                ? Page::query()->where('review_status', Page::REVIEW_PENDING)->count()
                : null,
            // Tareas entregadas y sin nota: todas para quien revisa todo, las de sus cursos para el docente
            'submissions_to_grade' => match (true) {
                $can('submissions.review') => TaskSubmission::query()->where('status', TaskSubmission::STATUS_SUBMITTED)->count(),
                $can('submissions.assigned.review') => TaskSubmission::query()->where('status', TaskSubmission::STATUS_SUBMITTED)
                    ->whereIn('course_id', Course::query()->whereHas('teachers', fn ($teachers) => $teachers->whereKey($user->id))->select('id'))
                    ->count(),
                default => null,
            },
            // Cuotas de ventas en curso que ya pasaron su fecha
            'overdue_installments' => $can('payments.confirm')
                ? OrderInstallment::query()->where('status', OrderInstallment::STATUS_PENDING)->where('due_date', '<', OrderPresenter::today())
                    ->whereIn('order_id', Order::query()->where('status', Order::STATUS_PARTIAL)->select('id'))->count()
                : null,
            // Horarios en los que el docente pidió cambios, para quien los arma
            'schedules_observed' => $can('schedules.manage')
                ? TeacherSchedule::query()->where('status', TeacherSchedule::STATUS_OBSERVED)->count()
                : null,
            // El horario propio del docente espera su confirmación
            'schedule_to_confirm' => $can('schedules.self')
                ? TeacherSchedule::query()->where('teacher_id', $user->id)->where('status', TeacherSchedule::STATUS_PENDING)->count()
                : null,
            // Hojas del Libro de Reclamaciones sin respuesta
            'claims_unanswered' => $can('claims.read')
                ? Claim::query()->where('status', '!=', Claim::STATUS_ANSWERED)->count()
                : null,
            // Matrículas que vencen en los próximos días
            'enrollments_expiring' => $can('enrollments.read')
                ? Enrollment::query()->inState(Enrollment::FILTER_EXPIRING)->count()
                : null,
            // Alumnos con acceso que no terminaron y no avanzan hace días
            'inactive_students' => $can('enrollments.read') ? $this->inactiveStudents() : null,
        ];
    }

    /** Días sin avance para considerar inactivo a un alumno con acceso */
    public const INACTIVE_DAYS = 14;

    private function inactiveStudents(): int
    {
        $since = now()->subDays(self::INACTIVE_DAYS);

        return (int) Enrollment::query()
            ->accessible()
            ->whereNull('completed_at')
            ->where('enrolled_at', '<=', $since)
            ->whereNotIn('id', EnrollmentProgress::query()->where('completed_at', '>=', $since)->select('enrollment_id'))
            ->distinct()
            ->count('student_id');
    }
}
