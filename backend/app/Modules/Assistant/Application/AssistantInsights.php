<?php

namespace App\Modules\Assistant\Application;

use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reports\Application\OverviewReport;
use App\Modules\Reports\Application\PendingSummary;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Dashboard\PanelPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras con que responde el asistente: ventas, lo más vendido,
 * matrículas y pendientes de un periodo. Cada sección solo se arma si la
 * persona tiene el permiso del módulo; si no, llega en null. Todo sale de
 * consultas agregadas, sobre la academia de la sesión.
 */
class AssistantInsights
{
    /** Días sin avance para considerar inactivo a un alumno con acceso */
    public const INACTIVE_DAYS = 14;

    /** Cuántos ítems trae cada lista */
    public const TOP = 5;

    public function __construct(
        private readonly PendingSummary $pending,
        private readonly SubscriptionService $subscriptions,
        private readonly TenantSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, PanelPeriod $period): array
    {
        $can = fn (string ...$permissions) => collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission));
        $currency = $this->settings->currency();

        return [
            'period' => ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), 'timezone' => $period->timezone],
            'currency' => $currency,
            'sales' => $can('orders.read', 'reports.read') ? $this->sales($period, $currency) : null,
            'enrollments' => match (true) {
                $can('enrollments.read', 'reports.read') => $this->enrollments($period, null),
                $can('courses.assigned.read') => $this->enrollments($period, $user),
                default => null,
            },
            'pending' => $this->pendingFor($user, $can),
            'plan' => $can('tenant_settings.read') ? $this->plan($user) : null,
        ];
    }

    /**
     * Lo cobrado (pago a pago, cuota a cuota), las ventas hechas y los cursos que más vendieron.
     *
     * @return array<string, mixed>
     */
    private function sales(PanelPeriod $period, string $currency): array
    {
        $collected = OverviewReport::collected($currency)->whereBetween('paid_at', [$period->start(), $period->end()]);

        return [
            'revenue' => number_format((float) (clone $collected)->sum('amount'), 2, '.', ''),
            'payments' => (clone $collected)->count(),
            'orders' => OverviewReport::soldOrders($currency, $period->start(), $period->end())->count(),
            'top' => OverviewReport::topCourses($period->start(), $period->end(), $currency, self::TOP),
        ];
    }

    /**
     * Matrículas nuevas del periodo y los cursos que más sumaron. El docente ve solo las de sus cursos.
     *
     * @return array<string, mixed>
     */
    private function enrollments(PanelPeriod $period, ?User $teacher): array
    {
        $query = Enrollment::query()
            ->whereBetween('enrolled_at', [$period->start(), $period->end()])
            ->when($teacher, fn (Builder $q) => $q->whereIn('course_id', $this->coursesOf($teacher)));

        return [
            'new' => (clone $query)->count(),
            'scope' => $teacher ? 'assigned' : 'all',
            'by_course' => (clone $query)
                ->join('courses', 'courses.id', '=', 'enrollments.course_id')
                ->select('enrollments.course_id', DB::raw('max(courses.title) as title'), DB::raw('count(*) as total'))
                ->groupBy('enrollments.course_id')
                ->orderByDesc('total')
                ->orderBy('title')
                ->limit(self::TOP)
                ->get()
                ->map(fn ($row) => ['course_id' => $row->course_id, 'title' => $row->title, 'total' => (int) $row->total])
                ->all(),
        ];
    }

    /**
     * Las cifras de la campana más dos propias del asistente: matrículas por vencer y alumnos sin avance.
     *
     * @return array<string, int|null>
     */
    private function pendingFor(User $user, callable $can): array
    {
        $sees = $can('enrollments.read');

        return $this->pending->for($user) + [
            'enrollments_expiring' => $sees ? Enrollment::query()->inState(Enrollment::FILTER_EXPIRING)->count() : null,
            'inactive_students' => $sees ? $this->inactiveStudents() : null,
        ];
    }

    /**
     * Alumnos con acceso a un curso que no terminaron y no avanzaron nada en los últimos días.
     */
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

    /**
     * @return array<string, mixed>|null
     */
    private function plan(User $user): ?array
    {
        return $this->subscriptions->present($this->subscriptions->of($user->tenant_id), $this->subscriptions->studentsIn($user->tenant_id));
    }

    private function coursesOf(User $teacher): Builder
    {
        return Course::query()->whereHas('teachers', fn ($teachers) => $teachers->whereKey($teacher->id))->select('id');
    }
}
