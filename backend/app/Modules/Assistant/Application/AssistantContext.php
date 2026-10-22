<?php

namespace App\Modules\Assistant\Application;

use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Application\CourseCommunity;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reports\Application\OverviewReport;
use App\Modules\Reports\Application\StudentDirectory;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Schedules\Application\NoClassDays;
use App\Modules\Schedules\Application\ScheduleService;
use App\Modules\Schedules\Domain\Models\TeacherSchedule;
use App\Modules\Tenancy\Application\TenantSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Las fichas con que responde el asistente: un curso, un alumno, los
 * horarios de un día y lo del propio alumno. Solo lectura; cada dato pide
 * el permiso de su módulo.
 */
class AssistantContext
{
    /** Días hacia atrás de las ventas de la ficha del curso */
    public const SALES_DAYS = 30;

    public function __construct(
        private readonly CourseAccess $access,
        private readonly StudentDirectory $students,
        private readonly ScheduleService $schedules,
        private readonly TenantSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function course(User $user, Course $course): array
    {
        $this->access->assertCanView($user, $course);

        $course->load(['teachers:id,first_name,last_name', 'currentVersion:id,number']);
        $currency = $this->settings->currency();
        $active = Enrollment::query()->where('course_id', $course->id)->where('status', Enrollment::STATUS_ACTIVE);

        return [
            'id' => $course->id,
            'title' => $course->title,
            'status' => $course->status,
            'version' => $course->currentVersion?->number,
            'price' => $course->price === null ? null : number_format((float) $course->price, 2, '.', ''),
            'currency' => $currency,
            'teachers' => $course->teachers->map(fn (User $teacher) => trim("{$teacher->first_name} {$teacher->last_name}"))->values(),
            'students' => [
                'with_access' => Enrollment::query()->where('course_id', $course->id)->accessible()->count(),
                'enrolled' => (clone $active)->count(),
                'completed' => (clone $active)->whereNotNull('completed_at')->count(),
                'expiring' => Enrollment::query()->where('course_id', $course->id)->inState(Enrollment::FILTER_EXPIRING)->count(),
            ],
            'pending_submissions' => $this->access->canReview($user, $course)
                ? TaskSubmission::query()->where('course_id', $course->id)->where('status', TaskSubmission::STATUS_SUBMITTED)->count()
                : null,
            'sales' => $user->hasPermission('orders.read') || $user->hasPermission('reports.read') ? $this->courseSales($course, $currency) : null,
            'rating' => CourseCommunity::ratings([$course->id])->get($course->id),
            'next_class' => $this->nextClass($course),
        ];
    }

    /**
     * Personas que coinciden con lo buscado (nombre, correo o documento), para elegir una.
     *
     * @return list<array<string, mixed>>
     */
    public function findStudents(string $search): array
    {
        return $this->students->query($search)
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->limit(5)
            ->get()
            ->map(fn (User $student) => $this->students->row($student))
            ->all();
    }

    /**
     * Ficha del alumno (la de Alumno 360) más lo que debe hoy.
     *
     * @return array<string, mixed>
     */
    public function student(User $student): array
    {
        return $this->students->profile($student) + ['debt' => $this->debtOf($student), 'grade_scale' => $this->settings->gradeScale()];
    }

    /**
     * Lo del propio alumno: sus cursos con avance y nota, lo que debe, sus
     * certificados y sus próximas clases en vivo.
     *
     * @return array<string, mixed>
     */
    public function mine(User $student): array
    {
        $profile = $this->students->profile($student);

        return [
            'enrollments' => $profile['enrollments'],
            'certificates' => $profile['certificates'],
            'debt' => $this->debtOf($student),
            'live_classes' => $this->schedules->liveClassesFor($student),
            'grade_scale' => $this->settings->gradeScale(),
        ];
    }

    /**
     * Quién tiene qué el día de la semana pedido (1 = lunes … 7 = domingo),
     * según los horarios confirmados.
     *
     * @return list<array<string, mixed>>
     */
    public function day(int $weekday): array
    {
        $schedules = TeacherSchedule::query()->whereNotNull('confirmed_blocks')->with('teacher:id,first_name,last_name,status')->get();
        $titles = Course::query()->pluck('title', 'id');

        return $schedules
            ->filter(fn (TeacherSchedule $schedule) => $schedule->teacher?->status === User::STATUS_ACTIVE)
            ->map(fn (TeacherSchedule $schedule) => [
                'teacher' => trim("{$schedule->teacher->first_name} {$schedule->teacher->last_name}"),
                'teacher_id' => $schedule->teacher_id,
                'blocks' => collect($schedule->confirmed_blocks)
                    ->where('day', $weekday)
                    ->map(fn (array $block) => [
                        'start' => $block['start'],
                        'end' => $block['end'],
                        'type' => $block['type'],
                        'course_title' => isset($block['course_id']) ? $titles->get($block['course_id']) : null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $row) => $row['blocks'] !== [])
            ->sortBy(fn (array $row) => $row['blocks'][0]['start'])
            ->values()
            ->all();
    }

    /**
     * Lo que debe una persona: órdenes pendientes de pago y cuotas sin pagar
     * de sus compras en cuotas, por moneda, con lo vencido y la próxima fecha.
     *
     * @return list<array{currency: string, total: string, overdue: string, next_due: ?string, orders: int}>
     */
    public function debtOf(User $student): array
    {
        $today = CarbonImmutable::now($this->settings->timezone())->toDateString();

        $pending = Order::query()
            ->where('customer_id', $student->id)
            ->inState(Order::STATUS_PENDING)
            ->with('currency')
            ->get(['id', 'currency_id', 'total_amount']);

        $installments = OrderInstallment::query()
            ->where('status', OrderInstallment::STATUS_PENDING)
            ->whereIn('order_id', Order::query()->where('customer_id', $student->id)->where('status', Order::STATUS_PARTIAL)->select('id'))
            ->with('order.currency')
            ->get(['id', 'order_id', 'amount', 'due_date']);

        $lines = $pending->map(fn (Order $order) => ['currency' => $order->currency?->code, 'amount' => (float) $order->total_amount, 'due' => null, 'order' => $order->id])
            ->concat($installments->map(fn (OrderInstallment $installment) => [
                'currency' => $installment->order->currency?->code,
                'amount' => (float) $installment->amount,
                'due' => $installment->due_date?->toDateString(),
                'order' => $installment->order_id,
            ]));

        return $lines->groupBy('currency')->map(fn ($group, $currency) => [
            'currency' => (string) $currency,
            'total' => number_format($group->sum('amount'), 2, '.', ''),
            'overdue' => number_format($group->filter(fn (array $line) => $line['due'] !== null && $line['due'] < $today)->sum('amount'), 2, '.', ''),
            'next_due' => $group->pluck('due')->filter(fn (?string $due) => $due !== null && $due >= $today)->sort()->first(),
            'orders' => $group->pluck('order')->unique()->count(),
        ])->values()->all();
    }

    /**
     * Ventas del curso en los últimos días: unidades, importe y la última venta.
     *
     * @return array<string, mixed>
     */
    private function courseSales(Course $course, string $currency): array
    {
        $end = CarbonImmutable::now();
        $start = $end->subDays(self::SALES_DAYS);

        $recent = OrderItem::query()
            ->where('course_id', $course->id)
            ->whereIn('order_id', OverviewReport::soldOrders($currency, $start, $end)->select('id'))
            ->select(DB::raw('coalesce(sum(quantity), 0) as units'), DB::raw('coalesce(sum(total_amount), 0) as revenue'))
            ->first();

        $last = Order::query()
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PARTIAL])
            ->whereHas('items', fn ($items) => $items->where('course_id', $course->id))
            ->max('paid_at');

        return [
            'days' => self::SALES_DAYS,
            'units' => (int) $recent->units,
            'revenue' => number_format((float) $recent->revenue, 2, '.', ''),
            'last_sale_at' => $last ? CarbonImmutable::parse($last)->toIso8601String() : null,
        ];
    }

    /**
     * La próxima clase en vivo del curso según los horarios confirmados.
     *
     * @return array{date: string, start: string, end: string, teacher: string}|null
     */
    private function nextClass(Course $course): ?array
    {
        $now = CarbonImmutable::now($this->settings->timezone());
        $next = null;
        $noClass = NoClassDays::between($course->teachers->pluck('id'), $now->toDateString(), $now->addWeeks(8)->toDateString());

        TeacherSchedule::query()
            ->whereIn('teacher_id', $course->teachers->pluck('id'))
            ->whereNotNull('confirmed_blocks')
            ->with('teacher:id,first_name,last_name')
            ->get()
            ->each(function (TeacherSchedule $schedule) use ($course, $now, $noClass, &$next) {
                $from = $schedule->confirmed_valid_from ? CarbonImmutable::parse($schedule->confirmed_valid_from->toDateString(), $now->timezone) : $now;

                foreach ($schedule->confirmed_blocks as $block) {
                    if ($block['type'] !== TeacherSchedule::TYPE_LIVE_CLASS || ($block['course_id'] ?? null) !== $course->id) {
                        continue;
                    }

                    // El primer día de la semana que toca, que no haya pasado y desde que vale el horario
                    $date = $now->startOfDay()->max($from->startOfDay());
                    while ($date->dayOfWeekIso !== $block['day'] || $date->setTimeFromTimeString($block['end'])->lessThan($now) || $noClass->blocks($schedule->teacher_id, $date->toDateString())) {
                        $date = $date->addDay();
                    }
                    $at = $date->setTimeFromTimeString($block['start']);

                    if ($next === null || $at->lessThan($next['at'])) {
                        $next = ['at' => $at, 'end' => $block['end'], 'teacher' => trim("{$schedule->teacher->first_name} {$schedule->teacher->last_name}")];
                    }
                }
            });

        return $next === null ? null : ['date' => $next['at']->toDateString(), 'start' => $next['at']->format('H:i'), 'end' => $next['end'], 'teacher' => $next['teacher']];
    }
}
