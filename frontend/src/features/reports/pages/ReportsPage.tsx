import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  ArrowDownRight,
  ArrowUpRight,
  Award,
  BarChart3,
  BookOpen,
  ClipboardList,
  Clock,
  CreditCard,
  GraduationCap,
  HandCoins,
  Minus,
  Receipt,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Card, CardHeader, EmptyState, ErrorState, IconChip, INPUT_CLASS, LoadingState, PageHeader, type Tone } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import { useAuth } from '../../auth/AuthContext';
import { bucketize, change, formatDay, isoDate, reportsApi, type Overview } from '../api';
import { ColumnChart } from '../components/ColumnChart';
import { ExportButton } from '../../../shared/ui/ExportButton';

interface Range {
  from: string;
  to: string;
}

function lastDays(days: number): Range {
  const to = new Date();
  const from = new Date();
  from.setDate(to.getDate() - (days - 1));
  return { from: isoDate(from), to: isoDate(to) };
}

function thisMonth(): Range {
  const to = new Date();
  return { from: isoDate(new Date(to.getFullYear(), to.getMonth(), 1)), to: isoDate(to) };
}

const PRESETS: { label: string; range: () => Range }[] = [
  { label: '7 días', range: () => lastDays(7) },
  { label: '30 días', range: () => lastDays(30) },
  { label: '90 días', range: () => lastDays(90) },
  { label: 'Este mes', range: thisMonth },
];

// Indicador del periodo con su variación frente al periodo anterior.
// La variación se lee por el signo y la flecha, no solo por el color.
const Kpi: React.FC<{ label: string; value: string; delta: number | null; hint?: string; icon: LucideIcon; tone: Tone }> = ({
  label,
  value,
  delta,
  hint,
  icon,
  tone,
}) => {
  const Trend = delta === null || delta === 0 ? Minus : delta > 0 ? ArrowUpRight : ArrowDownRight;
  const trendClass = delta === null || delta === 0 ? 'text-slate-500' : delta > 0 ? 'text-emerald-700' : 'text-rose-700';

  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-[13px] font-medium text-slate-500">{label}</p>
          <p className="mt-1.5 text-[22px] leading-7 font-bold text-slate-900 tracking-tight tnum break-words">{value}</p>
        </div>
        <IconChip icon={icon} tone={tone} />
      </div>
      <p className="mt-2 flex flex-wrap items-center gap-x-1.5 text-xs text-slate-500">
        <span className={`inline-flex items-center gap-0.5 font-semibold ${trendClass}`}>
          <Trend className="w-3.5 h-3.5" />
          {delta === null ? 'Nuevo' : `${delta > 0 ? '+' : ''}${delta} %`}
        </span>
        <span>{delta === null ? 'sin datos en el periodo anterior' : 'frente al periodo anterior'}</span>
      </p>
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </Card>
  );
};

const TodayItem: React.FC<{ icon: LucideIcon; label: string; value: string; hint?: string; alert?: boolean }> = ({ icon: Icon, label, value, hint, alert }) => (
  <div className="flex items-center gap-3 px-5 py-4 bg-white">
    <Icon className={`w-4.5 h-4.5 shrink-0 ${alert ? 'text-rose-600' : 'text-slate-500'}`} />
    <div className="min-w-0">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="text-[15px] font-semibold text-slate-900 tnum">
        {value}
        {hint && <span className="ml-1.5 text-xs font-normal text-slate-500">{hint}</span>}
      </p>
    </div>
  </div>
);

// Tablero de indicadores de la academia
export const ReportsPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const [range, setRange] = useState<Range>(() => lastDays(30));
  const [result, setResult] = useState<{ key: string; data: Overview } | { key: string; error: string } | null>(null);
  const [attempt, setAttempt] = useState(0);

  const key = `${range.from}|${range.to}|${attempt}`;
  const valid = range.from !== '' && range.to !== '' && range.from <= range.to;

  useEffect(() => {
    if (!valid) return;

    let active = true;
    reportsApi
      .overview(range.from, range.to)
      .then((data) => active && setResult({ key, data }))
      .catch((err) => active && setResult({ key, error: apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.') }));
    return () => {
      active = false;
    };
  }, [range.from, range.to, key, valid]);

  // Mientras llega el periodo nuevo se sigue viendo el anterior, atenuado
  const loading = valid && result?.key !== key;
  const report = result && 'data' in result ? result.data : null;
  const error = result && 'error' in result && result.key === key ? result.error : null;

  const revenue = useMemo(() => (report ? bucketize(report.series, (point) => Number(point.revenue)) : null), [report]);
  const enrollments = useMemo(() => (report ? bucketize(report.series, (point) => point.new_enrollments) : null), [report]);

  const money = (value: string | number) => formatMoney(value, report?.currency ?? 'PEN');
  const activePreset = PRESETS.find((preset) => {
    const candidate = preset.range();
    return candidate.from === range.from && candidate.to === range.to;
  });

  return (
    <div>
      <PageHeader
        title="Reportes"
        icon={BarChart3}
        subtitle="Cómo van las ventas, las matrículas y el avance de tus alumnos."
        action={valid && hasPermission('reports.export') && <ExportButton path="/reports/overview/export" params={{ from: range.from, to: range.to }} filename={`reporte-${range.from}-a-${range.to}.xlsx`} />}
      />

      {/* Filtros: una sola fila, sobre todo lo que filtran */}
      <Card className="mb-5">
        <div className="flex flex-wrap items-center gap-3 px-5 py-4">
          <div className="flex flex-wrap gap-1.5" role="group" aria-label="Periodo">
            {PRESETS.map((preset) => (
              <button
                key={preset.label}
                type="button"
                aria-pressed={activePreset === preset}
                onClick={() => setRange(preset.range())}
                className={`h-9 px-3 rounded-lg text-[13px] font-semibold cursor-pointer transition-colors ${
                  activePreset === preset ? 'bg-brand-700 text-white' : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50'
                }`}
              >
                {preset.label}
              </button>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-2 text-[13px] text-slate-600">
            <label className="flex items-center gap-2">
              Desde
              <input
                type="date"
                className={`${INPUT_CLASS} !w-40`}
                value={range.from}
                max={range.to || undefined}
                onChange={(event) => setRange({ ...range, from: event.target.value })}
              />
            </label>
            <label className="flex items-center gap-2">
              hasta
              <input
                type="date"
                className={`${INPUT_CLASS} !w-40`}
                value={range.to}
                min={range.from || undefined}
                onChange={(event) => setRange({ ...range, to: event.target.value })}
              />
            </label>
          </div>
        </div>
      </Card>

      {error && (
        <Card>
          <ErrorState message={error} onRetry={() => setAttempt((n) => n + 1)} />
        </Card>
      )}
      {!report && !error && <LoadingState />}

      {report && !error && (
        <div className={`space-y-5 transition-opacity ${loading ? 'opacity-50' : ''}`} aria-busy={loading}>
          <p className="text-xs text-slate-500">
            Del {formatDay(report.period.from, true)} al {formatDay(report.period.to, true)} ({report.period.days}{' '}
            {report.period.days === 1 ? 'día' : 'días'}). Se compara con el periodo anterior, del {formatDay(report.period.previous_from, true)} al{' '}
            {formatDay(report.period.previous_to, true)}.
          </p>

          <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <Kpi
              label="Ingresos"
              value={money(report.totals.revenue)}
              delta={change(Number(report.totals.revenue), Number(report.previous.revenue))}
              hint={
                Number(report.totals.installment_revenue) > 0
                  ? `Lo cobrado en el periodo; ${money(report.totals.installment_revenue)} entraron por cuotas.`
                  : 'Lo cobrado en el periodo, pago a pago.'
              }
              icon={CreditCard}
              tone="success"
            />
            <Kpi
              label="Órdenes pagadas"
              value={String(report.totals.paid_orders)}
              delta={change(report.totals.paid_orders, report.previous.paid_orders)}
              hint="Las que quedaron pagadas por completo. Incluye las inscripciones a cursos gratuitos."
              icon={Receipt}
              tone="brand"
            />
            <Kpi
              label="Matrículas nuevas"
              value={String(report.totals.new_enrollments)}
              delta={change(report.totals.new_enrollments, report.previous.new_enrollments)}
              hint={`${report.totals.purchased_enrollments} por compra, ${report.totals.new_enrollments - report.totals.purchased_enrollments} hechas por el personal.`}
              icon={ClipboardList}
              tone="info"
            />
            <Kpi
              label="Alumnos activos"
              value={String(report.totals.active_students)}
              delta={change(report.totals.active_students, report.previous.active_students)}
              hint="Completaron al menos un recurso o actividad."
              icon={Users}
              tone="violet"
            />
            <Kpi
              label="Cursos completados"
              value={String(report.totals.completed_enrollments)}
              delta={change(report.totals.completed_enrollments, report.previous.completed_enrollments)}
              hint="Matrículas que llegaron al 100 %."
              icon={GraduationCap}
              tone="info"
            />
            <Kpi
              label="Certificados emitidos"
              value={String(report.totals.certificates_issued)}
              delta={change(report.totals.certificates_issued, report.previous.certificates_issued)}
              icon={Award}
              tone="warning"
            />
          </section>

          <section className="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <Card>
              <CardHeader title="Ingresos" text={`Lo cobrado, por ${revenue?.unit}. Cada cuota cuenta el día en que se paga.`} />
              <div className="px-5 pb-5 pt-8">
                {revenue && <ColumnChart buckets={revenue.buckets} color="bg-brand-500" format={(value) => money(value)} measure="Ingresos" />}
              </div>
            </Card>
            <Card>
              <CardHeader title="Matrículas nuevas" text={`Por compra y hechas por el personal, por ${enrollments?.unit}.`} />
              <div className="px-5 pb-5 pt-8">
                {enrollments && (
                  <ColumnChart buckets={enrollments.buckets} color="bg-teal-600" format={(value) => String(value)} measure="Matrículas nuevas" integer />
                )}
              </div>
            </Card>
          </section>

          <section className="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
            <Card>
              <CardHeader title="Cursos más vendidos" text="Por ingreso de sus órdenes pagadas en el periodo." />
              {report.top_courses.length === 0 ? (
                <EmptyState icon={CreditCard} title="Sin ventas en este periodo" text="Cuando se confirme el pago de una orden, el curso aparecerá aquí." />
              ) : (
                <ol className="divide-y divide-slate-100">
                  {report.top_courses.map((course, index) => (
                    <li key={course.course_id} className="flex items-center gap-4 px-5 py-3.5">
                      <span className="w-6 text-[13px] font-semibold text-slate-500 tnum">{index + 1}</span>
                      <div className="flex-1 min-w-0">
                        <p className="text-[13px] font-semibold text-slate-900 truncate">{course.title}</p>
                        <p className="text-xs text-slate-500">
                          {course.units} {course.units === 1 ? 'venta' : 'ventas'}
                        </p>
                      </div>
                      <span className="text-[13px] font-semibold text-slate-900 tnum whitespace-nowrap">{money(course.revenue)}</span>
                    </li>
                  ))}
                </ol>
              )}
            </Card>

            <Card>
              <CardHeader title="Finalización por curso" text="De todos los matriculados hasta hoy, cuántos completaron el curso." />
              {report.completion.length === 0 ? (
                <EmptyState icon={GraduationCap} title="Aún no hay matrículas" text="Cuando haya alumnos matriculados, verás aquí cuántos terminan cada curso." />
              ) : (
                <ul className="divide-y divide-slate-100">
                  {report.completion.map((course) => (
                    <li key={course.course_id} className="px-5 py-3.5">
                      <div className="flex items-baseline justify-between gap-4">
                        <p className="text-[13px] font-semibold text-slate-900 truncate">{course.title}</p>
                        <p className="text-[13px] font-semibold text-slate-900 tnum whitespace-nowrap">{course.rate} %</p>
                      </div>
                      <div className="mt-2 h-2 rounded-full bg-slate-200/80 overflow-hidden" aria-hidden="true">
                        <span className="block h-full rounded-full bg-brand-500" style={{ width: `${course.rate}%` }} />
                      </div>
                      <p className="mt-1.5 text-xs text-slate-500">
                        {course.completed} de {course.enrolled} {course.enrolled === 1 ? 'matriculado' : 'matriculados'}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </section>

          <Card>
            <CardHeader title="Situación de hoy" text="Cifras al momento de consultar; no dependen del periodo elegido." />
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-px bg-slate-100 rounded-b-2xl overflow-hidden">
              <TodayItem
                icon={Clock}
                label="Órdenes pendientes de pago"
                value={String(report.today.pending_orders)}
                hint={report.today.pending_orders > 0 ? money(report.today.pending_amount) : undefined}
              />
              <TodayItem
                icon={HandCoins}
                label="Por cobrar en cuotas"
                value={money(report.today.receivable_amount)}
                hint={report.today.receivable_installments > 0 ? `${report.today.receivable_installments} ${report.today.receivable_installments === 1 ? 'cuota' : 'cuotas'}` : undefined}
              />
              <TodayItem
                icon={AlertTriangle}
                label="Cuotas vencidas"
                value={money(report.today.overdue_amount)}
                hint={report.today.overdue_installments > 0 ? `${report.today.overdue_installments} ${report.today.overdue_installments === 1 ? 'cuota' : 'cuotas'}` : undefined}
                alert={report.today.overdue_installments > 0}
              />
              <TodayItem icon={Users} label="Alumnos con acceso vigente" value={String(report.today.students_with_access)} />
              <TodayItem icon={BookOpen} label="Cursos publicados" value={String(report.today.published_courses)} />
              <TodayItem icon={Award} label="Certificados vigentes" value={String(report.today.valid_certificates)} />
            </div>
          </Card>
        </div>
      )}
    </div>
  );
};
