<?php

namespace App\Modules\Reports\Application;

use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Modules\Sales\Domain\Models\Currency;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores base de la organización (RF-REP-001, FP-22). Cada cifra tiene
 * una definición fija, documentada en docs/avances; el periodo se interpreta
 * en la zona horaria de la organización e incluye ambos días.
 */
class OverviewReport
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = $this->settings->get($tenant)['timezone'];
        $currency = $this->settings->currency();

        // Días completos en la hora de la organización…
        $localStart = $from->shiftTimezone($timezone)->startOfDay();
        $localEnd = $to->shiftTimezone($timezone)->endOfDay();
        $days = (int) floor($localStart->diffInDays($localEnd)) + 1;

        // …llevados a la hora de la aplicación, que es en la que se consulta la base
        $start = $localStart->setTimezone(config('app.timezone'));
        $end = $localEnd->setTimezone(config('app.timezone'));

        // Periodo anterior de la misma duración, para comparar
        $previousStart = $localStart->subDays($days)->setTimezone(config('app.timezone'));
        $previousEnd = $start->subSecond();

        return [
            'period' => [
                'from' => $localStart->toDateString(),
                'to' => $localEnd->toDateString(),
                'days' => $days,
                'previous_from' => $localStart->subDays($days)->toDateString(),
                'previous_to' => $localStart->subDay()->toDateString(),
                'timezone' => $timezone,
            ],
            'currency' => $currency,
            'totals' => $this->totals($start, $end, $currency),
            'previous' => $this->totals($previousStart, $previousEnd, $currency),
            'today' => $this->today($currency, now($timezone)->toDateString()),
            'series' => $this->series($localStart, $localEnd, $start, $end, $timezone, $currency),
            'top_courses' => self::topCourses($start, $end, $currency),
            'completion' => $this->completion(),
        ];
    }

    /**
     * Cifras del periodo.
     *
     * @return array<string, int|string>
     */
    private function totals(CarbonImmutable $start, CarbonImmutable $end, string $currency): array
    {
        $paid = $this->paidOrders($currency)->whereBetween('paid_at', [$start, $end]);
        $collected = self::collected($currency)->whereBetween('paid_at', [$start, $end]);
        $enrollments = Enrollment::query()->whereBetween('enrolled_at', [$start, $end]);

        return [
            // Órdenes que quedaron pagadas por completo en el periodo, en la moneda de la organización
            'paid_orders' => (clone $paid)->count(),
            // Lo cobrado en el periodo, pago a pago: una venta en cuotas cuenta cada cuota el día en que entra
            'revenue' => self::money((clone $collected)->sum('amount')),
            'installment_revenue' => self::money((clone $collected)->whereNotNull('installment_id')->sum('amount')),
            // Matrículas creadas en el periodo, sin importar si luego se cancelaron
            'new_enrollments' => (clone $enrollments)->count(),
            'purchased_enrollments' => (clone $enrollments)->where('source', Enrollment::SOURCE_PURCHASE)->count(),
            // Alumnos distintos que completaron al menos un recurso o actividad en el periodo
            'active_students' => (int) EnrollmentProgress::query()
                ->join('enrollments', 'enrollments.id', '=', 'enrollment_progress.enrollment_id')
                ->whereBetween('enrollment_progress.completed_at', [$start, $end])
                ->distinct()
                ->count('enrollments.student_id'),
            // Matrículas que llegaron al 100 % en el periodo
            'completed_enrollments' => Enrollment::query()->whereBetween('completed_at', [$start, $end])->count(),
            // Certificados emitidos en el periodo, incluidos los que después se anularon
            'certificates_issued' => Certificate::query()->whereBetween('issued_at', [$start, $end])->count(),
        ];
    }

    /**
     * Situación al momento de consultar, independiente del periodo.
     *
     * @return array<string, int|string>
     */
    private function today(string $currency, string $today): array
    {
        $pending = Order::query()->inState(Order::STATUS_PENDING)->whereHas('currency', fn ($q) => $q->where('code', $currency));
        // Cuotas sin pagar de las ventas en cuotas en curso: lo que falta cobrar de lo ya vendido
        $receivable = OrderInstallment::query()
            ->where('status', OrderInstallment::STATUS_PENDING)
            ->whereIn('order_id', Order::query()->where('status', Order::STATUS_PARTIAL)->whereHas('currency', fn ($q) => $q->where('code', $currency))->select('id'));
        $overdue = (clone $receivable)->where('due_date', '<', $today);

        return [
            'pending_orders' => (clone $pending)->count(),
            'pending_amount' => self::money((clone $pending)->sum('total_amount')),
            'receivable_installments' => (clone $receivable)->count(),
            'receivable_amount' => self::money((clone $receivable)->sum('amount')),
            // De eso, lo que ya pasó su fecha
            'overdue_installments' => (clone $overdue)->count(),
            'overdue_amount' => self::money((clone $overdue)->sum('amount')),
            // Alumnos distintos con al menos una matrícula que hoy les da acceso
            'students_with_access' => (int) Enrollment::query()->accessible()->distinct()->count('student_id'),
            'published_courses' => Course::query()->where('status', Course::STATUS_PUBLISHED)->count(),
            'valid_certificates' => Certificate::query()->where('status', Certificate::STATUS_ISSUED)->count(),
        ];
    }

    /**
     * Lo cobrado, las órdenes pagadas y las matrículas nuevas por día, con los días sin movimiento en cero.
     *
     * @return list<array{date: string, revenue: string, paid_orders: int, new_enrollments: int}>
     */
    private function series(
        CarbonImmutable $localStart,
        CarbonImmutable $localEnd,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $timezone,
        string $currency,
    ): array {
        // El día de cada registro es el de la organización, no el del servidor
        $sales = $this->paidOrders($currency)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('((paid_at at time zone ?)::date)::text as day, count(*) as total', [$timezone])
            ->groupBy('day')
            ->pluck('total', 'day');

        $revenue = self::collected($currency)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('((paid_at at time zone ?)::date)::text as day, sum(amount) as total', [$timezone])
            ->groupBy('day')
            ->pluck('total', 'day');

        $enrollments = Enrollment::query()
            ->whereBetween('enrolled_at', [$start, $end])
            ->selectRaw('((enrolled_at at time zone ?)::date)::text as day, count(*) as total', [$timezone])
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];

        for ($date = $localStart; $date->lte($localEnd); $date = $date->addDay()) {
            $key = $date->toDateString();

            $series[] = [
                'date' => $key,
                'revenue' => self::money($revenue[$key] ?? 0),
                'paid_orders' => (int) ($sales[$key] ?? 0),
                'new_enrollments' => (int) ($enrollments[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Cursos más vendidos del periodo: por el importe de las ventas hechas en él.
     *
     * @return list<array{course_id: string, title: string, units: int, revenue: string}>
     */
    public static function topCourses(CarbonImmutable $start, CarbonImmutable $end, string $currency, int $limit = 5): array
    {
        return OrderItem::query()
            ->whereIn('order_id', self::soldOrders($currency, $start, $end)->select('id'))
            ->select('course_id', DB::raw('max(title) as title'), DB::raw('sum(quantity) as units'), DB::raw('sum(total_amount) as revenue'))
            ->groupBy('course_id')
            ->orderByDesc('revenue')
            ->orderByDesc('units')
            ->limit($limit)
            ->get()
            ->map(fn (OrderItem $item) => [
                'course_id' => $item->course_id,
                'title' => $item->title,
                'units' => (int) $item->units,
                'revenue' => self::money($item->revenue),
            ])
            ->all();
    }

    /**
     * Finalización por curso, de siempre: de las matrículas no canceladas, cuántas llegaron al 100 %.
     *
     * @return list<array{course_id: string, title: string, enrolled: int, completed: int, rate: int}>
     */
    private function completion(): array
    {
        return Enrollment::query()
            ->where('enrollments.status', Enrollment::STATUS_ACTIVE)
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->select(
                'enrollments.course_id',
                DB::raw('max(courses.title) as title'),
                DB::raw('count(*) as enrolled'),
                DB::raw('count(enrollments.completed_at) as completed')
            )
            ->groupBy('enrollments.course_id')
            ->orderByDesc('enrolled')
            ->orderBy('title')
            ->limit(10)
            ->get()
            ->map(fn (Enrollment $row) => [
                'course_id' => $row->course_id,
                'title' => $row->title,
                'enrolled' => (int) $row->enrolled,
                'completed' => (int) $row->completed,
                'rate' => (int) round($row->completed * 100 / $row->enrolled),
            ])
            ->all();
    }

    /**
     * Órdenes pagadas en la moneda de la organización: no se suman importes de monedas distintas.
     */
    private function paidOrders(string $currency)
    {
        return Order::query()
            ->where('status', Order::STATUS_PAID)
            ->whereHas('currency', fn ($q) => $q->where('code', $currency));
    }

    /**
     * Ventas hechas en el periodo: la orden que se pagó de una vez cuenta el
     * día de su pago; la que se paga en cuotas, el día de la primera, que es
     * cuando el comprador recibe el acceso.
     */
    public static function soldOrders(string $currency, CarbonImmutable $start, CarbonImmutable $end)
    {
        return Order::query()
            ->whereHas('currency', fn ($q) => $q->where('code', $currency))
            ->where(fn ($sold) => $sold
                ->where(fn ($single) => $single
                    ->where('status', Order::STATUS_PAID)
                    ->whereDoesntHave('installments')
                    ->whereBetween('paid_at', [$start, $end]))
                ->orWhere(fn ($plan) => $plan
                    ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PARTIAL])
                    ->whereHas('installments', fn ($first) => $first->where('number', 1)->whereBetween('paid_at', [$start, $end]))));
    }

    /**
     * Pagos recibidos y no devueltos, en la moneda de la organización. Un pago reembolsado deja de contar.
     */
    public static function collected(string $currency)
    {
        return Payment::query()
            ->where('status', Payment::STATUS_CONFIRMED)
            ->whereIn('currency_id', Currency::query()->where('code', $currency)->select('id'));
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
