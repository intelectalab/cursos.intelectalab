<?php

namespace App\Modules\Reports\Application;

use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Claims\Domain\Models\Claim;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Currency;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Support\Domain\Models\Ticket;
use App\Shared\Dashboard\Panel;
use App\Shared\Dashboard\PanelPeriod;
use Carbon\CarbonImmutable;

/**
 * Panel de una academia: cómo va lo académico, las ventas, las personas y
 * la atención en un periodo. Cada pestaña la ve quien puede entrar al
 * módulo del que sale; las cifras de ventas se suman en la moneda de la
 * academia, como en Reportes.
 */
class AcademyDashboard
{
    /** Pestañas del panel y los permisos que abren cada una (basta uno) */
    public const SECTIONS = [
        'academic' => ['courses.read', 'enrollments.read'],
        'sales' => ['orders.read'],
        'people' => ['students.read', 'users.read'],
        'support' => ['tickets.read', 'claims.read'],
    ];

    private const ENROLLMENT_STATES = [
        Enrollment::STATUS_ACTIVE => 'En curso',
        Enrollment::STATE_COMPLETED => 'Completadas',
        Enrollment::STATE_UPCOMING => 'Por iniciar',
        Enrollment::STATE_EXPIRED => 'Vencidas',
        Enrollment::STATUS_CANCELLED => 'Canceladas',
    ];

    private const METHODS = [
        Payment::METHOD_TRANSFER => 'Transferencia',
        Payment::METHOD_YAPE => 'Yape',
        Payment::METHOD_CARD => 'Tarjeta',
        Payment::METHOD_WALLET => 'Saldo de Mercado Pago',
        Payment::METHOD_PAYPAL => 'PayPal',
        Payment::METHOD_CASH => 'Efectivo',
        Payment::METHOD_OTHER => 'Otro',
    ];

    private const TICKET_CATEGORIES = [
        'ACCESS' => 'Acceso',
        'COURSE' => 'Cursos',
        'PAYMENT' => 'Pagos',
        'CERTIFICATE' => 'Certificados',
        'OTHER' => 'Otros',
    ];

    /**
     * Las pestañas que esta persona puede ver, en su orden.
     *
     * @return list<string>
     */
    public static function sectionsFor(User $user): array
    {
        return array_values(array_filter(
            array_keys(self::SECTIONS),
            fn (string $section) => collect(self::SECTIONS[$section])->contains(fn (string $permission) => $user->hasPermission($permission))
        ));
    }

    public function section(string $section, PanelPeriod $period, string $currency): Panel
    {
        return match ($section) {
            'academic' => $this->academic($period),
            'sales' => $this->sales($period, $currency),
            'people' => $this->people($period),
            'support' => $this->support($period),
        };
    }

    private function academic(PanelPeriod $period): Panel
    {
        [$previousStart, $previousEnd] = $period->previous();
        $between = fn (string $column, $query) => [
            (clone $query)->whereBetween($column, [$period->start(), $period->end()])->count(),
            (clone $query)->whereBetween($column, [$previousStart, $previousEnd])->count(),
        ];

        [$enrolled, $enrolledBefore] = $between('enrolled_at', Enrollment::query());
        [$completed, $completedBefore] = $between('completed_at', Enrollment::query());
        [$certificates, $certificatesBefore] = $between('issued_at', Certificate::query());
        $published = Course::query()->where('status', Course::STATUS_PUBLISHED)->count();
        $drafts = Course::query()->where('status', '!=', Course::STATUS_PUBLISHED)->count();
        $toGrade = TaskSubmission::query()->where('status', TaskSubmission::STATUS_SUBMITTED)->count();

        $states = [];
        foreach (array_keys(self::ENROLLMENT_STATES) as $state) {
            $states[$state] = Enrollment::query()->inState($state)->count();
        }
        // «En curso» incluye las completadas que siguen vigentes: se muestran aparte
        $states[Enrollment::STATUS_ACTIVE] -= $states[Enrollment::STATE_COMPLETED];

        $byMonth = $period->perMonth(Enrollment::query()->toBase()->where('enrolled_at', '>=', $period->monthsStart()), 'enrolled_at', 'count(*)');

        $topCourses = Enrollment::query()->toBase()
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->whereBetween('enrollments.enrolled_at', [$period->start(), $period->end()])
            ->groupBy('enrollments.course_id')
            ->selectRaw('max(courses.title) as title, count(*) as total')
            ->orderByDesc('total')->limit(8)
            ->pluck('total', 'title')->map(fn ($value) => (int) $value)->all();

        $pendingByCourse = TaskSubmission::query()->toBase()->where('status', TaskSubmission::STATUS_SUBMITTED)
            ->groupBy('course_id')->selectRaw('course_id, count(*) as total')->pluck('total', 'course_id');

        $rows = Enrollment::query()->toBase()
            ->where('enrollments.status', Enrollment::STATUS_ACTIVE)
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->groupBy('enrollments.course_id')
            ->selectRaw('enrollments.course_id, max(courses.title) as title, count(*) as enrolled, count(enrollments.completed_at) as completed')
            ->orderByDesc('enrolled')->orderBy('title')->limit(15)
            ->get()
            ->map(fn (object $row) => [
                'title' => $row->title,
                'enrolled' => (int) $row->enrolled,
                'completed' => (int) $row->completed,
                'rate' => (int) round($row->completed * 100 / max(1, $row->enrolled)),
                'to_grade' => (int) ($pendingByCourse[$row->course_id] ?? 0),
            ])->all();

        return (new Panel('Académico'))
            ->card('Cursos publicados', $published, tone: 'violet', hint: $drafts > 0 ? "{$drafts} en borrador o archivados" : null)
            ->card('Matrículas nuevas', $enrolled, tone: 'brand', hint: 'En el periodo', previous: $enrolledBefore)
            ->card('Cursos completados', $completed, tone: 'success', hint: 'Matrículas que llegaron al 100 %', previous: $completedBefore)
            ->card('Certificados emitidos', $certificates, tone: 'info', previous: $certificatesBefore)
            ->card('Tareas por calificar', $toGrade, tone: $toGrade > 0 ? 'warning' : 'success', hint: 'Entregadas y sin nota')
            ->chart('Matrículas nuevas por mes', 'bar', $byMonth)
            ->chart('Matrículas por situación', 'donut', Panel::labelled($states, self::ENROLLMENT_STATES), note: 'Situación de hoy')
            ->chart('Cursos con más matrículas en el periodo', 'hbar', $topCourses)
            ->table(
                'Avance por curso',
                ['title' => 'Curso', 'enrolled' => 'Matriculados', 'completed' => 'Completaron', 'rate' => 'Finalización (%)', 'to_grade' => 'Tareas por calificar'],
                $rows,
                ['enrolled' => 'number', 'completed' => 'number', 'rate' => 'number', 'to_grade' => 'number'],
                'Todavía no hay matrículas.'
            );
    }

    private function sales(PanelPeriod $period, string $currency): Panel
    {
        [$previousStart, $previousEnd] = $period->previous();
        $collected = fn ($start, $end) => OverviewReport::collected($currency)->whereBetween('paid_at', [$start, $end]);

        $inPeriod = $collected($period->start(), $period->end());
        $total = (float) (clone $inPeriod)->sum('amount');
        $byInstallments = (float) (clone $inPeriod)->whereNotNull('installment_id')->sum('amount');
        $before = (float) $collected($previousStart, $previousEnd)->sum('amount');

        $sold = OverviewReport::soldOrders($currency, CarbonImmutable::instance($period->start()), CarbonImmutable::instance($period->end()))->count();
        $soldBefore = OverviewReport::soldOrders($currency, CarbonImmutable::instance($previousStart), CarbonImmutable::instance($previousEnd))->count();

        $inCurrency = fn ($query) => $query->whereHas('currency', fn ($q) => $q->where('code', $currency));
        $pending = $inCurrency(Order::query()->inState(Order::STATUS_PENDING));
        $pendingAmount = (float) (clone $pending)->sum('total_amount');
        $receivable = OrderInstallment::query()->where('status', OrderInstallment::STATUS_PENDING)
            ->whereIn('order_id', $inCurrency(Order::query()->where('status', Order::STATUS_PARTIAL))->select('id'));
        $receivableAmount = (float) (clone $receivable)->sum('amount');
        $overdueAmount = (float) (clone $receivable)->where('due_date', '<', now($period->timezone)->toDateString())->sum('amount');

        $byMonth = $period->perMonth(OverviewReport::collected($currency)->toBase()->where('paid_at', '>=', $period->monthsStart()), 'paid_at', 'sum(amount)', money: true);
        $byMethod = (clone $inPeriod)->toBase()->groupBy('method')->selectRaw('method, sum(amount) as total')->orderByDesc('total')
            ->pluck('total', 'method')->map(fn ($value) => round((float) $value, 2))->all();

        $topCourses = OrderItem::query()->toBase()
            ->whereIn('order_id', OverviewReport::soldOrders($currency, CarbonImmutable::instance($period->start()), CarbonImmutable::instance($period->end()))->select('id'))
            ->groupBy('course_id')->selectRaw('max(title) as title, sum(total_amount) as total')
            ->orderByDesc('total')->limit(8)
            ->pluck('total', 'title')->map(fn ($value) => round((float) $value, 2))->all();

        $latest = Payment::query()
            ->with(['order:id,number,customer_id,package_title', 'order.customer:id,first_name,last_name', 'order.items:id,order_id,title', 'installment:id,number'])
            ->where('status', Payment::STATUS_CONFIRMED)
            ->whereIn('currency_id', Currency::query()->where('code', $currency)->select('id'))
            ->orderByDesc('paid_at')->limit(10)->get();

        return (new Panel('Ventas'))
            ->card('Cobrado en el periodo', round($total, 2), 'money', 'success', $byInstallments > 0 ? 'De ello, '.self::money($byInstallments, $currency).' en cuotas' : null, round($before, 2), $currency)
            ->card('Ventas', $sold, tone: 'brand', hint: 'Pagadas, o con la primera cuota pagada', previous: $soldBefore)
            ->card('Órdenes por pagar', (clone $pending)->count(), tone: 'violet', hint: 'Por '.self::money($pendingAmount, $currency))
            ->card('Por cobrar en cuotas', round($receivableAmount, 2), 'money', 'info', (clone $receivable)->count().' cuotas pendientes', currency: $currency)
            ->card('Cuotas vencidas', round($overdueAmount, 2), 'money', $overdueAmount > 0 ? 'danger' : 'success', 'Ya pasó su fecha', currency: $currency)
            ->chart('Cobrado por mes', 'bar', $byMonth, 'money', $currency)
            ->chart('Cobrado por medio de pago en el periodo', 'donut', Panel::labelled($byMethod, self::METHODS), 'money', $currency)
            ->chart('Cursos más vendidos en el periodo', 'hbar', $topCourses, 'money', $currency, 'Por el importe de las ventas')
            ->table(
                'Últimos cobros',
                ['order' => 'Orden', 'customer' => 'Comprador', 'concept' => 'Concepto', 'amount' => 'Importe', 'method' => 'Medio', 'paid_at' => 'Fecha de pago'],
                $latest->map(fn (Payment $payment) => [
                    'order' => 'N.º '.str_pad((string) $payment->order?->number, 5, '0', STR_PAD_LEFT),
                    'customer' => trim(($payment->order?->customer?->first_name ?? '').' '.($payment->order?->customer?->last_name ?? '')) ?: null,
                    'concept' => ($payment->order?->package_title ?? $payment->order?->items->first()?->title).($payment->installment ? " · cuota {$payment->installment->number}" : ''),
                    'amount' => (float) $payment->amount,
                    'currency' => $currency,
                    'method' => self::METHODS[$payment->method] ?? $payment->method,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                ])->all(),
                ['amount' => 'money', 'paid_at' => 'datetime'],
                'Todavía no hay cobros.'
            );
    }

    private function people(PanelPeriod $period): Panel
    {
        [$previousStart, $previousEnd] = $period->previous();
        $students = fn () => User::query()->whereHas('roles', fn ($q) => $q->where('code', RoleCatalog::STUDENT));
        $newStudents = (clone $students())->whereBetween('created_at', [$period->start(), $period->end()])->count();
        $newBefore = (clone $students())->whereBetween('created_at', [$previousStart, $previousEnd])->count();

        // Alumnos distintos que completaron al menos un recurso o actividad
        $active = fn () => EnrollmentProgress::query()->toBase()->join('enrollments', 'enrollments.id', '=', 'enrollment_progress.enrollment_id');
        $activeNow = (int) $active()->whereBetween('enrollment_progress.completed_at', [$period->start(), $period->end()])->distinct()->count('enrollments.student_id');
        $activeBefore = (int) $active()->whereBetween('enrollment_progress.completed_at', [$previousStart, $previousEnd])->distinct()->count('enrollments.student_id');

        $withAccess = (int) Enrollment::query()->accessible()->distinct()->count('student_id');
        $teachers = User::query()->where('status', 'ACTIVE')->whereHas('roles', fn ($q) => $q->where('code', RoleCatalog::TEACHER))->count();
        $users = User::query()->where('status', 'ACTIVE')->count();

        $newByMonth = $period->perMonth($students()->toBase()->where('users.created_at', '>=', $period->monthsStart()), 'users.created_at', 'count(*)');
        $activeByMonth = $period->perMonth(
            $active()->where('enrollment_progress.completed_at', '>=', $period->monthsStart()),
            'enrollment_progress.completed_at',
            'count(distinct enrollments.student_id)'
        );

        $byRole = User::query()->toBase()
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('users.status', 'ACTIVE')
            ->groupBy('roles.id')->selectRaw('max(roles.name) as name, count(*) as total')
            ->orderByDesc('total')
            ->pluck('total', 'name')->map(fn ($value) => (int) $value)->all();

        $latest = $students()
            ->select(['id', 'first_name', 'last_name', 'email', 'created_at', 'last_login_at'])
            ->addSelect(['enrollments_count' => Enrollment::query()->selectRaw('count(*)')->whereColumn('enrollments.student_id', 'users.id')])
            ->whereBetween('created_at', [$period->start(), $period->end()])
            ->orderByDesc('created_at')->limit(10)
            ->get();

        return (new Panel('Personas'))
            ->card('Alumnos con acceso', $withAccess, tone: 'brand', hint: 'Con al menos una matrícula vigente')
            ->card('Alumnos nuevos', $newStudents, tone: 'success', hint: 'Cuentas creadas en el periodo', previous: $newBefore)
            ->card('Alumnos activos', $activeNow, tone: 'info', hint: 'Avanzaron en algún curso en el periodo', previous: $activeBefore)
            ->card('Docentes', $teachers, tone: 'violet')
            ->card('Usuarios activos', $users, tone: 'brand', hint: 'Todas las cuentas que pueden entrar')
            ->chart('Alumnos nuevos por mes', 'bar', $newByMonth)
            ->chart('Alumnos activos por mes', 'bar', $activeByMonth, note: 'Alumnos que avanzaron en algún curso ese mes')
            ->chart('Usuarios por rol', 'donut', $byRole, note: 'Quien tiene dos roles cuenta en ambos')
            ->table(
                'Alumnos nuevos del periodo',
                ['name' => 'Alumno', 'email' => 'Correo', 'created_at' => 'Alta', 'enrollments' => 'Matrículas', 'last_login_at' => 'Último acceso'],
                $latest->map(fn (User $user) => [
                    'name' => trim("{$user->first_name} {$user->last_name}"),
                    'email' => $user->email,
                    'created_at' => $user->created_at->toIso8601String(),
                    'enrollments' => (int) $user->enrollments_count,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                ])->all(),
                ['created_at' => 'date', 'enrollments' => 'number', 'last_login_at' => 'datetime'],
                'No hubo alumnos nuevos en el periodo.'
            );
    }

    private function support(PanelPeriod $period): Panel
    {
        [$previousStart, $previousEnd] = $period->previous();
        $open = Ticket::query()->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS]);
        $waiting = (clone $open)->where('last_message_by', Ticket::BY_REQUESTER);
        $newTickets = Ticket::query()->whereBetween('created_at', [$period->start(), $period->end()])->count();
        $newTicketsBefore = Ticket::query()->whereBetween('created_at', [$previousStart, $previousEnd])->count();

        $unanswered = Claim::query()->where('status', '!=', Claim::STATUS_ANSWERED)->orderBy('due_at')->get(['id', 'code', 'type', 'first_name', 'last_name', 'created_at', 'due_at', 'status']);
        $overdue = $unanswered->filter(fn (Claim $claim) => $claim->isOverdue())->count();
        $newClaims = Claim::query()->whereBetween('created_at', [$period->start(), $period->end()])->count();
        $newClaimsBefore = Claim::query()->whereBetween('created_at', [$previousStart, $previousEnd])->count();

        $byMonth = $period->perMonth(Ticket::query()->toBase()->where('created_at', '>=', $period->monthsStart()), 'created_at', 'count(*)');
        $byCategory = Ticket::query()->toBase()->whereBetween('created_at', [$period->start(), $period->end()])
            ->groupBy('category')->selectRaw('category, count(*) as total')->pluck('total', 'category')->map(fn ($value) => (int) $value)->all();
        $claimsByType = Claim::query()->toBase()->whereBetween('created_at', [$period->start(), $period->end()])
            ->groupBy('type')->selectRaw('type, count(*) as total')->pluck('total', 'type')->map(fn ($value) => (int) $value)->all();

        $rows = (clone $waiting)->with('requester:id,first_name,last_name')->orderBy('last_message_at')->limit(15)->get()
            ->map(fn (Ticket $ticket) => [
                'kind' => 'Consulta',
                'code' => '#'.str_pad((string) $ticket->number, 4, '0', STR_PAD_LEFT),
                'subject' => $ticket->subject,
                'who' => trim(($ticket->requester?->first_name ?? '').' '.($ticket->requester?->last_name ?? '')) ?: null,
                'since' => ($ticket->last_message_at ?? $ticket->created_at)->toIso8601String(),
                'due' => null,
            ])
            ->concat($unanswered->take(15)->map(fn (Claim $claim) => [
                'kind' => $claim->type === Claim::TYPE_CLAIM ? 'Reclamo' : 'Queja',
                'code' => $claim->code,
                'subject' => $claim->isOverdue() ? 'Plazo vencido' : 'Sin responder',
                'who' => $claim->consumerName(),
                'since' => $claim->created_at->toIso8601String(),
                'due' => $claim->due_at->toDateString(),
            ]))
            ->sortBy('since')->values()->all();

        return (new Panel('Atención'))
            ->card('Consultas abiertas', (clone $open)->count(), tone: 'brand')
            ->card('Esperan respuesta', (clone $waiting)->count(), tone: (clone $waiting)->exists() ? 'warning' : 'success', hint: 'Lo último lo escribió quien consulta')
            ->card('Consultas nuevas', $newTickets, tone: 'info', hint: 'En el periodo', previous: $newTicketsBefore)
            ->card('Reclamos sin responder', $unanswered->count(), tone: $overdue > 0 ? 'danger' : ($unanswered->isNotEmpty() ? 'warning' : 'success'), hint: $overdue > 0 ? "{$overdue} con el plazo vencido" : 'Ninguno con el plazo vencido')
            ->card('Reclamos y quejas nuevos', $newClaims, tone: 'violet', hint: 'En el periodo', previous: $newClaimsBefore)
            ->chart('Consultas nuevas por mes', 'bar', $byMonth)
            ->chart('Consultas del periodo por tema', 'donut', Panel::labelled($byCategory, self::TICKET_CATEGORIES))
            ->chart('Libro de Reclamaciones en el periodo', 'donut', Panel::labelled($claimsByType, [Claim::TYPE_CLAIM => 'Reclamos', Claim::TYPE_COMPLAINT => 'Quejas']))
            ->table(
                'Lo que espera respuesta',
                ['kind' => 'Tipo', 'code' => 'Código', 'subject' => 'Asunto', 'who' => 'De', 'since' => 'Desde', 'due' => 'Plazo'],
                $rows,
                ['since' => 'date', 'due' => 'date'],
                'Nada espera respuesta.'
            );
    }

    private static function money(float $amount, string $currency): string
    {
        return ($currency === 'PEN' ? 'S/' : $currency).' '.number_format($amount, 2);
    }
}
