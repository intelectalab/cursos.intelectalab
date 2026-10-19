<?php

namespace App\Modules\Platform\Application;

use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Modules\Sales\Domain\Models\GatewayError;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Dashboard\Panel;
use App\Shared\Dashboard\PanelPeriod;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Panel de la consola de plataforma: cómo están las academias, lo que la
 * plataforma cobra por los planes, cuánto se usa el sistema y lo que
 * requiere atención. Corre en modo sistema: ve todas las academias.
 */
class PlatformDashboard
{
    public const SECTIONS = ['academies', 'income', 'usage', 'alerts'];

    /** Los planes se cobran en soles: es la moneda en la que se suman los ingresos */
    private const CURRENCY = 'PEN';

    /** Zona horaria con la que la plataforma lee los periodos */
    public const TIMEZONE = 'America/Lima';

    private const SITUATIONS = [
        'PLAN' => 'Con plan pagado',
        'TRIAL' => 'En prueba gratuita',
        'GRACE' => 'Vencida, en días de gracia',
        'READ_ONLY' => 'En solo lectura',
        'UNLIMITED' => 'Sin plan (sin tope)',
        'SUSPENDED' => 'Suspendida',
    ];

    private const METHODS = [
        'MERCADOPAGO' => 'Mercado Pago',
        'PAYPAL' => 'PayPal',
        'TRANSFER' => 'Transferencia',
        'YAPE' => 'Yape',
        'CASH' => 'Efectivo',
        'OTHER' => 'Otro',
    ];

    public function section(string $section, PanelPeriod $period): Panel
    {
        return match ($section) {
            'academies' => $this->academies($period),
            'income' => $this->income($period),
            'usage' => $this->usage($period),
            'alerts' => $this->alerts($period),
        };
    }

    private function academies(PanelPeriod $period): Panel
    {
        $tenants = Tenant::query()->orderByDesc('created_at')->get(['id', 'name', 'slug', 'status', 'created_at']);
        $subscriptions = TenantSubscription::query()->with('plan:id,name')->get()->keyBy('tenant_id');
        $students = $this->studentsByTenant();
        $situation = $tenants->mapWithKeys(fn (Tenant $tenant) => [$tenant->id => $this->situation($tenant, $subscriptions->get($tenant->id))]);

        [$previousStart, $previousEnd] = $period->previous();
        $new = $tenants->filter(fn (Tenant $tenant) => $tenant->created_at->between($period->start(), $period->end()))->count();
        $newBefore = $tenants->filter(fn (Tenant $tenant) => $tenant->created_at->between($previousStart, $previousEnd))->count();
        $attention = $situation->filter(fn (string $value) => in_array($value, ['GRACE', 'READ_ONLY', 'SUSPENDED'], true))->count();

        $byPlan = $tenants->countBy(function (Tenant $tenant) use ($subscriptions, $situation) {
            $subscription = $subscriptions->get($tenant->id);

            return match (true) {
                $subscription === null || $situation[$tenant->id] === 'UNLIMITED' => 'Sin plan',
                $subscription->status === TenantSubscription::STATUS_TRIAL => 'Prueba gratuita',
                default => $subscription->plan?->name ?? 'Sin plan',
            };
        })->sortDesc()->all();

        $byMonth = $this->perMonth($period, DB::table('tenants')->where('created_at', '>=', $period->monthsStart()), 'created_at', 'count(*)');

        return (new Panel('Academias'))
            ->card('Academias', $tenants->count(), tone: 'brand', hint: $tenants->where('status', Tenant::STATUS_ACTIVE)->count().' activas')
            ->card('Nuevas en el periodo', $new, tone: 'success', hint: 'Registradas en el periodo', previous: $newBefore)
            ->card('Con plan pagado', $situation->filter(fn ($value) => $value === 'PLAN')->count(), tone: 'info')
            ->card('En prueba gratuita', $situation->filter(fn ($value) => $value === 'TRIAL')->count(), tone: 'violet')
            ->card('Requieren atención', $attention, tone: $attention > 0 ? 'danger' : 'success', hint: 'En gracia, en solo lectura o suspendidas')
            ->chart('Academias por situación', 'donut', $this->labelled($situation->countBy()->all(), self::SITUATIONS))
            ->chart('Academias nuevas por mes', 'bar', $byMonth)
            ->chart('Academias por plan', 'hbar', $byPlan)
            ->table(
                'Últimas academias registradas',
                ['name' => 'Academia', 'slug' => 'Dirección', 'created_at' => 'Alta', 'situation' => 'Situación', 'students' => 'Alumnos activos'],
                $tenants->take(10)->map(fn (Tenant $tenant) => [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'created_at' => $tenant->created_at->toIso8601String(),
                    'situation' => self::SITUATIONS[$situation[$tenant->id]],
                    'students' => (int) ($students[$tenant->id] ?? 0),
                ])->values()->all(),
                ['created_at' => 'date', 'students' => 'number'],
                'Todavía no hay academias.'
            );
    }

    private function income(PanelPeriod $period): Panel
    {
        $paid = fn () => DB::table('plan_orders')->where('status', PlanOrder::STATUS_PAID)->where('currency', self::CURRENCY);
        [$previousStart, $previousEnd] = $period->previous();

        $inPeriod = $paid()->whereBetween('paid_at', [$period->start(), $period->end()]);
        $total = (float) (clone $inPeriod)->sum('amount');
        $count = (clone $inPeriod)->count();
        $before = (float) $paid()->whereBetween('paid_at', [$previousStart, $previousEnd])->sum('amount');

        $pending = DB::table('plan_orders')->where('status', PlanOrder::STATUS_PENDING);
        $pendingAmount = (float) (clone $pending)->sum('amount');
        $informed = (clone $pending)->where(fn ($query) => $query->whereNotNull('payer_reference')->orWhereNotNull('proof_file_id'))->count();

        // Lo que entra cada mes con los planes vigentes: precio del plan entre los meses que cubre
        $monthly = TenantSubscription::query()->with('plan:id,price,billing_months,currency')->whereNotNull('plan_id')->get()
            ->filter(fn (TenantSubscription $subscription) => $subscription->state() === TenantSubscription::STATE_ACTIVE && $subscription->plan?->currency === self::CURRENCY)
            ->sum(fn (TenantSubscription $subscription) => (float) $subscription->plan->price / max(1, (int) $subscription->plan->billing_months));

        $byPlan = (clone $inPeriod)->selectRaw('plan_name, sum(amount) as total')->groupBy('plan_name')->orderByDesc('total')->pluck('total', 'plan_name')->map(fn ($value) => round((float) $value, 2))->all();
        $byMethod = (clone $inPeriod)->selectRaw('coalesce(gateway, payment_method, \'OTHER\') as method, sum(amount) as total')->groupBy('method')->orderByDesc('total')->pluck('total', 'method')
            ->map(fn ($value) => round((float) $value, 2))->all();
        $byMonth = $this->perMonth($period, $paid()->where('paid_at', '>=', $period->monthsStart()), 'paid_at', 'sum(amount)', money: true);

        $latest = PlanOrder::query()->with('tenant:id,name')->where('status', PlanOrder::STATUS_PAID)->orderByDesc('paid_at')->limit(10)->get();

        return (new Panel('Ingresos'))
            ->card('Cobrado en el periodo', $total, 'money', 'success', 'Planes pagados', $before, self::CURRENCY)
            ->card('Pagos confirmados', $count, tone: 'brand', hint: $count > 0 ? 'Promedio S/ '.number_format($total / $count, 2) : null)
            ->card('Ingreso mensual de los planes vigentes', round($monthly, 2), 'money', 'info', 'Precio de cada plan entre los meses que cubre', currency: self::CURRENCY)
            ->card('Por confirmar', $pendingAmount, 'money', $informed > 0 ? 'warning' : 'violet', $informed.' con pago informado', currency: self::CURRENCY)
            ->chart('Cobrado por mes', 'bar', $byMonth, 'money', self::CURRENCY)
            ->chart('Cobrado por plan en el periodo', 'donut', $byPlan, 'money', self::CURRENCY)
            ->chart('Cobrado por medio de pago en el periodo', 'donut', $this->labelled($byMethod, self::METHODS), 'money', self::CURRENCY)
            ->table(
                'Últimos pagos de planes confirmados',
                ['code' => 'Orden', 'tenant' => 'Academia', 'plan' => 'Plan', 'amount' => 'Importe', 'method' => 'Medio', 'paid_at' => 'Fecha de pago'],
                $latest->map(fn (PlanOrder $order) => [
                    'code' => $order->code(),
                    'tenant' => $order->tenant?->name,
                    'plan' => $order->plan_name,
                    'amount' => (float) $order->amount,
                    'currency' => $order->currency,
                    'method' => self::METHODS[$order->gateway ?? $order->payment_method ?? 'OTHER'] ?? ($order->gateway ?? $order->payment_method),
                    'paid_at' => $order->paid_at?->toIso8601String(),
                ])->all(),
                ['amount' => 'money', 'paid_at' => 'datetime'],
                'Todavía no hay pagos de planes confirmados.'
            );
    }

    private function usage(PanelPeriod $period): Panel
    {
        [$previousStart, $previousEnd] = $period->previous();
        $names = Tenant::query()->pluck('name', 'id');
        $students = $this->studentsByTenant();
        $courses = DB::table('courses')->where('status', 'PUBLISHED')->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as value')->pluck('value', 'tenant_id');
        $enrollments = DB::table('enrollments')->whereBetween('created_at', [$period->start(), $period->end()])->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as value')->pluck('value', 'tenant_id');
        $sales = $this->salesByTenant($period->start(), $period->end());
        $lastLogin = DB::table('users')->groupBy('tenant_id')->selectRaw('tenant_id, max(last_login_at) as value')->pluck('value', 'tenant_id');

        $enrollmentsBefore = DB::table('enrollments')->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $salesBefore = array_sum($this->salesByTenant($previousStart, $previousEnd)->all());
        $byMonth = $this->perMonth($period, DB::table('enrollments')->where('created_at', '>=', $period->monthsStart()), 'created_at', 'count(*)');

        $top = fn (Collection $values) => $values->filter(fn ($value) => (float) $value > 0)->sortDesc()->take(8)
            ->mapWithKeys(fn ($value, string $tenantId) => [$names[$tenantId] ?? 'Academia' => round((float) $value, 2)])->all();

        $rows = $names->keys()
            ->map(fn (string $tenantId) => [
                'name' => $names[$tenantId],
                'students' => (int) ($students[$tenantId] ?? 0),
                'courses' => (int) ($courses[$tenantId] ?? 0),
                'enrollments' => (int) ($enrollments[$tenantId] ?? 0),
                'sales' => round((float) ($sales[$tenantId] ?? 0), 2),
                'currency' => self::CURRENCY,
                'last_login_at' => isset($lastLogin[$tenantId]) ? Carbon::parse($lastLogin[$tenantId])->toIso8601String() : null,
            ])
            ->sortByDesc(fn (array $row) => [$row['students'], $row['enrollments']])->take(20)->values()->all();

        return (new Panel('Uso'))
            ->card('Alumnos activos', array_sum($students->all()), tone: 'brand', hint: 'Con al menos una matrícula activa')
            ->card('Usuarios activos', DB::table('users')->where('status', 'ACTIVE')->count(), tone: 'info')
            ->card('Cursos publicados', array_sum($courses->all()), tone: 'violet')
            ->card('Matrículas nuevas', array_sum($enrollments->all()), tone: 'success', hint: 'En el periodo', previous: $enrollmentsBefore)
            ->card('Ventas de las academias', round(array_sum($sales->all()), 2), 'money', 'warning', 'Cobrado en soles en el periodo', round($salesBefore, 2), self::CURRENCY)
            ->chart('Matrículas nuevas por mes', 'bar', $byMonth)
            ->chart('Academias con más alumnos activos', 'hbar', $top($students))
            ->chart('Academias con más ventas en el periodo', 'hbar', $top($sales), 'money', self::CURRENCY, 'Solo lo cobrado en soles')
            ->table(
                'Actividad por academia',
                ['name' => 'Academia', 'students' => 'Alumnos activos', 'courses' => 'Cursos publicados', 'enrollments' => 'Matrículas en el periodo', 'sales' => 'Ventas en el periodo', 'last_login_at' => 'Último acceso'],
                $rows,
                ['students' => 'number', 'courses' => 'number', 'enrollments' => 'number', 'sales' => 'money', 'last_login_at' => 'datetime'],
                'Todavía no hay academias.'
            );
    }

    private function alerts(PanelPeriod $period): Panel
    {
        $names = Tenant::query()->pluck('name', 'id');
        $students = $this->studentsByTenant();
        $subscriptions = TenantSubscription::query()->get();
        $now = now();
        $rows = [];

        $pending = PlanOrder::query()->with('tenant:id,name')->where('status', PlanOrder::STATUS_PENDING)
            ->where(fn ($query) => $query->whereNotNull('payer_reference')->orWhereNotNull('proof_file_id'))->orderBy('created_at')->get();
        foreach ($pending as $order) {
            $rows[] = ['tenant' => $order->tenant?->name, 'reason' => 'Pago de plan informado', 'detail' => "{$order->code()} · {$order->plan_name} · S/ ".number_format((float) $order->amount, 2), 'at' => $order->updated_at->toIso8601String()];
        }

        $review = PlanOrder::query()->with('tenant:id,name')->whereNotNull('review_reason')->latest('updated_at')->get();
        foreach ($review as $order) {
            $rows[] = ['tenant' => $order->tenant?->name, 'reason' => 'Cobro en revisión', 'detail' => "{$order->code()} · {$order->review_reason}", 'at' => $order->updated_at->toIso8601String()];
        }

        $expiring = $grace = $readOnly = $fullQuota = 0;
        $quota = [];
        foreach ($subscriptions as $subscription) {
            $name = $names[$subscription->tenant_id] ?? 'Academia';
            $access = $subscription->access();
            $endsAt = $subscription->endsAt();

            if ($access === TenantSubscription::ACCESS_READ_ONLY) {
                $readOnly++;
                $rows[] = ['tenant' => $name, 'reason' => 'En solo lectura', 'detail' => 'Venció el '.$endsAt?->setTimezone(self::TIMEZONE)->format('d/m/Y'), 'at' => $subscription->graceEndsAt()?->toIso8601String()];
            } elseif ($access === TenantSubscription::ACCESS_GRACE) {
                $grace++;
                $rows[] = ['tenant' => $name, 'reason' => 'En días de gracia', 'detail' => 'Pasa a solo lectura el '.$subscription->graceEndsAt()?->setTimezone(self::TIMEZONE)->format('d/m/Y'), 'at' => $endsAt?->toIso8601String()];
            } elseif ($endsAt !== null && $endsAt->between($now, $now->copy()->addDays(7))) {
                $expiring++;
                $what = $subscription->status === TenantSubscription::STATUS_TRIAL ? 'La prueba gratuita' : 'El plan';
                $rows[] = ['tenant' => $name, 'reason' => 'Por vencer', 'detail' => "{$what} vence el ".$endsAt->setTimezone(self::TIMEZONE)->format('d/m/Y'), 'at' => $endsAt->toIso8601String()];
            }

            if ($subscription->max_students !== null && $subscription->max_students > 0) {
                $used = (int) ($students[$subscription->tenant_id] ?? 0);
                $percent = round($used * 100 / $subscription->max_students, 1);
                $quota[$name] = $percent;

                if ($percent >= 90) {
                    $fullQuota++;
                    $rows[] = ['tenant' => $name, 'reason' => 'Cupo casi lleno', 'detail' => "{$used} de {$subscription->max_students} alumnos", 'at' => null];
                }
            }
        }

        $errors = GatewayError::query()->where('scope', GatewayError::SCOPE_PLATFORM)->where('last_at', '>=', $now->copy()->subDays(7))->orderByDesc('last_at')->get();
        foreach ($errors->take(5) as $error) {
            $rows[] = ['tenant' => $names[$error->tenant_id] ?? 'Plataforma', 'reason' => 'Error de pasarela', 'detail' => mb_strimwidth($error->message, 0, 140, '…'), 'at' => $error->last_at->toIso8601String()];
        }

        $ordersByStatus = DB::table('plan_orders')->whereBetween('created_at', [$period->start(), $period->end()])->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status')->all();
        arsort($quota);

        return (new Panel('Alertas'))
            ->card('Pagos informados por confirmar', $pending->count(), tone: $pending->isNotEmpty() ? 'warning' : 'success')
            ->card('Cobros en revisión', $review->count(), tone: $review->isNotEmpty() ? 'danger' : 'success')
            ->card('Por vencer en 7 días', $expiring, tone: $expiring > 0 ? 'warning' : 'success')
            ->card('En gracia o solo lectura', $grace + $readOnly, tone: $grace + $readOnly > 0 ? 'danger' : 'success', hint: "{$grace} en gracia · {$readOnly} en solo lectura")
            ->card('Cupo al 90 % o más', $fullQuota, tone: $fullQuota > 0 ? 'warning' : 'success')
            ->chart('Órdenes de plan del periodo, por estado', 'donut', $this->labelled($ordersByStatus, ['PENDING' => 'Por confirmar', 'PAID' => 'Pagadas', 'CANCELLED' => 'Canceladas', 'EXPIRED' => 'Vencidas']))
            ->chart('Uso del cupo de alumnos', 'hbar', array_slice($quota, 0, 8, true), 'percent', note: 'Academias con tope de alumnos')
            ->table(
                'Lo que requiere atención',
                ['tenant' => 'Academia', 'reason' => 'Motivo', 'detail' => 'Detalle', 'at' => 'Fecha'],
                $rows,
                ['at' => 'date'],
                'Nada requiere atención.'
            );
    }

    /**
     * @return Collection<string, int> alumnos con matrícula activa por academia
     */
    private function studentsByTenant(): Collection
    {
        return DB::table('enrollments')->where('status', 'ACTIVE')->groupBy('tenant_id')->selectRaw('tenant_id, count(distinct student_id) as value')->pluck('value', 'tenant_id')->map(fn ($value) => (int) $value);
    }

    /**
     * Lo cobrado en soles por cada academia (pagos confirmados por su fecha de pago).
     *
     * @return Collection<string, float>
     */
    private function salesByTenant(\DateTimeInterface $from, \DateTimeInterface $to): Collection
    {
        return DB::table('payments')
            ->join('currencies', 'currencies.id', '=', 'payments.currency_id')
            ->where('payments.status', 'CONFIRMED')->where('currencies.code', self::CURRENCY)
            ->whereBetween('payments.paid_at', [$from, $to])
            ->groupBy('payments.tenant_id')->selectRaw('payments.tenant_id, sum(payments.amount) as value')
            ->pluck('value', 'tenant_id')->map(fn ($value) => (float) $value);
    }

    /**
     * Valores por mes de los últimos 12 meses, con los meses vacíos en cero.
     *
     * @param  Builder  $query
     * @return array<string, float|int>
     */
    private function perMonth(PanelPeriod $period, $query, string $column, string $aggregate, bool $money = false): array
    {
        $values = $query->where($column, '<=', $period->end())
            ->selectRaw($period->monthExpression($column).' as month, '.$aggregate.' as total')
            ->groupBy('month')->pluck('total', 'month');

        $result = [];
        foreach ($period->months() as $key => $label) {
            $result[$label] = $money ? round((float) ($values[$key] ?? 0), 2) : (int) ($values[$key] ?? 0);
        }

        return $result;
    }

    /**
     * Cambia las claves por su nombre en pantalla, en el orden de los nombres.
     *
     * @param  array<string, float|int>  $values
     * @param  array<string, string>  $labels
     * @return array<string, float|int>
     */
    private function labelled(array $values, array $labels): array
    {
        $result = [];
        foreach ($labels as $key => $label) {
            if (($values[$key] ?? 0) > 0) {
                $result[$label] = $values[$key];
            }
        }
        foreach ($values as $key => $value) {
            if (! isset($labels[$key]) && $value > 0) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function situation(Tenant $tenant, ?TenantSubscription $subscription): string
    {
        if (! $tenant->isActive()) {
            return 'SUSPENDED';
        }

        if ($subscription === null || $subscription->state() === TenantSubscription::STATE_UNLIMITED) {
            return 'UNLIMITED';
        }

        return match ($subscription->access()) {
            TenantSubscription::ACCESS_READ_ONLY => 'READ_ONLY',
            TenantSubscription::ACCESS_GRACE => 'GRACE',
            default => $subscription->state() === TenantSubscription::STATE_TRIAL ? 'TRIAL' : 'PLAN',
        };
    }
}
