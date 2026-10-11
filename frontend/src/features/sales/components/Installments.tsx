import React, { useEffect, useState } from 'react';
import { CalendarClock, Plus, Trash2 } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { FilterCards, type FilterCard } from '../../../shared/ui/filters';
import { formatDateTime, formatMoney, orderCode, PAYMENT_GATEWAY_LABEL, PAYMENT_METHOD_LABEL, salesApi, type DueInstallment, type Installment, type Order } from '../api';

// Tope de cuotas de un plan de pagos (lo exige también el servidor)
const MIN_INSTALLMENTS = 2;
const MAX_INSTALLMENTS = 12;

const pad = (value: number) => String(value).padStart(2, '0');
const isoDay = (date: Date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

// Fecha sin hora: se muestra tal cual, sin correrla por la zona horaria
function formatDay(value: string): string {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
}

function InstallmentBadge({ installment }: { installment: Installment }) {
  if (installment.status === 'PAID') return <Badge tone="success">Pagada</Badge>;
  if (installment.overdue) return <Badge tone="danger">Vencida</Badge>;
  return <Badge tone="warning">Por pagar</Badge>;
}

// Plan de pagos de una orden: cada cuota, cuándo vence y cómo se pagó
export const InstallmentList: React.FC<{ order: Order }> = ({ order }) => {
  if (order.installments.length === 0 || !order.debt) return null;

  return (
    <div className="pt-4 border-t border-slate-200/80">
      <div className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
        <h3 className="text-[13px] font-semibold text-slate-900">Plan de pagos · {order.installments.length} cuotas</h3>
        <p className="text-xs text-slate-500 tnum">
          Pagado {formatMoney(order.debt.paid_amount, order.currency)} · Falta {formatMoney(order.debt.pending_amount, order.currency)}
        </p>
      </div>
      <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
        {order.installments.map((installment) => (
          <li key={installment.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3.5 py-2.5 text-[13px]">
            <div className="min-w-0">
              <p className="font-semibold text-slate-900">
                Cuota {installment.number} <span className="font-normal text-slate-500">· vence el {formatDay(installment.due_date)}</span>
              </p>
              {installment.status === 'PAID' && (
                <p className="text-xs text-slate-500">
                  Pagada el {formatDateTime(installment.paid_at)}
                  {installment.payment
                    ? ` · ${PAYMENT_METHOD_LABEL[installment.payment.method]}${
                        installment.payment.gateway !== 'MANUAL' && PAYMENT_GATEWAY_LABEL[installment.payment.gateway] ? ` · ${PAYMENT_GATEWAY_LABEL[installment.payment.gateway]}` : ''
                      }${installment.payment.reference ? ` · n.º ${installment.payment.reference}` : ''}`
                    : ''}
                </p>
              )}
            </div>
            <div className="flex items-center gap-3">
              <InstallmentBadge installment={installment} />
              <span className="font-medium text-slate-900 tnum whitespace-nowrap">{formatMoney(installment.amount, order.currency)}</span>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
};

interface PlanRow {
  amount: string;
  due_date: string;
}

// Reparte el total en cuotas iguales, una por mes desde hoy; la última absorbe los céntimos
function split(total: number, count: number, existing: PlanRow[]): PlanRow[] {
  const cents = Math.round(total * 100);
  const base = Math.floor(cents / count);
  const today = new Date();

  return Array.from({ length: count }, (_, index) => {
    const date = new Date(today.getFullYear(), today.getMonth() + index, today.getDate());
    return {
      amount: ((index === count - 1 ? cents - base * (count - 1) : base) / 100).toFixed(2),
      due_date: existing[index]?.due_date ?? isoDay(date),
    };
  });
}

// El personal arma el plan de pagos de una orden pendiente
export const InstallmentPlanForm: React.FC<{ order: Order; formId: string; onSubmit: (rows: PlanRow[]) => void }> = ({ order, formId, onSubmit }) => {
  const total = Number(order.total_amount);
  const [rows, setRows] = useState<PlanRow[]>(() =>
    order.installments.length > 0 ? order.installments.map((installment) => ({ amount: installment.amount, due_date: installment.due_date })) : split(total, 3, []),
  );

  const sum = rows.reduce((accumulated, row) => accumulated + Math.round((Number(row.amount) || 0) * 100), 0);
  const difference = Math.round(total * 100) - sum;
  const ordered = rows.every((row, index) => index === 0 || row.due_date > rows[index - 1].due_date);
  const today = isoDay(new Date());

  const update = (index: number, patch: Partial<PlanRow>) => setRows((current) => current.map((row, position) => (position === index ? { ...row, ...patch } : row)));

  return (
    <form
      id={formId}
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault();
        if (difference === 0 && ordered) onSubmit(rows);
      }}
    >
      <Alert tone="info">
        El comprador entra al curso al pagar la <strong>primera cuota</strong>. Si no la paga hasta su fecha, la orden vence. Las siguientes, si no se pagan a tiempo, quedan como deuda y
        te avisamos; el acceso no se quita solo.
      </Alert>

      <div className="flex flex-wrap items-end gap-3">
        <div className="w-40">
          <Field label="Número de cuotas">
            <select className={INPUT_CLASS} value={rows.length} onChange={(event) => setRows((current) => split(total, Number(event.target.value), current))}>
              {Array.from({ length: MAX_INSTALLMENTS - MIN_INSTALLMENTS + 1 }, (_, index) => index + MIN_INSTALLMENTS).map((count) => (
                <option key={count} value={count}>
                  {count} cuotas
                </option>
              ))}
            </select>
          </Field>
        </div>
        <Button size="sm" variant="ghost" onClick={() => setRows((current) => split(total, current.length, []))}>
          Repartir en partes iguales, una por mes
        </Button>
      </div>

      <ul className="space-y-2">
        {rows.map((row, index) => (
          <li key={index} className="grid grid-cols-[4.5rem_1fr_1fr_auto] items-end gap-2">
            <span className="text-[13px] font-semibold text-slate-700 pb-2.5">Cuota {index + 1}</span>
            <Field label={index === 0 ? `Importe (${order.currency})` : ''}>
              <input
                className={INPUT_CLASS}
                type="number"
                inputMode="decimal"
                step="0.01"
                min="0.01"
                required
                aria-label={`Importe de la cuota ${index + 1}`}
                value={row.amount}
                onChange={(event) => update(index, { amount: event.target.value })}
              />
            </Field>
            <Field label={index === 0 ? 'Vence el' : ''}>
              <input
                className={INPUT_CLASS}
                type="date"
                required
                min={today}
                aria-label={`Vencimiento de la cuota ${index + 1}`}
                value={row.due_date}
                onChange={(event) => update(index, { due_date: event.target.value })}
              />
            </Field>
            <Button
              size="sm"
              variant="ghost"
              aria-label={`Quitar la cuota ${index + 1}`}
              disabled={rows.length <= MIN_INSTALLMENTS}
              onClick={() => setRows((current) => current.filter((_, position) => position !== index))}
            >
              <Trash2 className="w-4 h-4" />
            </Button>
          </li>
        ))}
      </ul>

      {rows.length < MAX_INSTALLMENTS && (
        <Button
          size="sm"
          icon={<Plus className="w-3.5 h-3.5" />}
          onClick={() =>
            setRows((current) => {
              const last = current[current.length - 1];
              const [year, month, day] = last.due_date.split('-').map(Number);
              return [...current, { amount: (Math.max(difference, 0) / 100).toFixed(2), due_date: isoDay(new Date(year, month, day)) }];
            })
          }
        >
          Agregar cuota
        </Button>
      )}

      <p className={`text-[13px] font-medium tnum ${difference === 0 ? 'text-emerald-700' : 'text-rose-700'}`} role="status">
        {difference === 0
          ? `Las cuotas suman ${formatMoney(order.total_amount, order.currency)}, el total de la orden.`
          : difference > 0
            ? `Faltan ${formatMoney(difference / 100, order.currency)} para llegar al total de ${formatMoney(order.total_amount, order.currency)}.`
            : `Sobran ${formatMoney(-difference / 100, order.currency)}: las cuotas pasan el total de ${formatMoney(order.total_amount, order.currency)}.`}
      </p>
      {!ordered && <Alert>Cada cuota debe vencer después de la anterior.</Alert>}
    </form>
  );
};

type DueFilter = '' | 'OVERDUE' | 'UPCOMING';

// Cuotas por cobrar de toda la academia: quién debe, cuánto y desde cuándo
export const DueInstallments: React.FC<{ version: number; onOpen: (orderId: string) => void }> = ({ version, onOpen }) => {
  const [filter, setFilter] = useState<DueFilter>('');
  const [result, setResult] = useState<Awaited<ReturnType<typeof salesApi.dueInstallments>> | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    salesApi
      .dueInstallments(filter || undefined)
      .then((data) => {
        if (!active) return;
        setResult(data);
        setError(null);
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar las cuotas por cobrar.')));
    return () => {
      active = false;
    };
  }, [filter, version]);

  // Sin ninguna venta en cuotas no hay nada que mostrar: la sección no ocupa lugar
  if (!error && result && result.counts.ALL === 0) return null;

  const cards: FilterCard[] = [
    { key: '', label: 'Cuotas por cobrar', value: result?.counts.ALL ?? null, icon: CalendarClock, tone: 'brand', help: 'Cuotas sin pagar de las órdenes que ya recibieron su primer pago.' },
    { key: 'OVERDUE', label: 'Vencidas', value: result?.counts.OVERDUE ?? null, icon: CalendarClock, tone: 'danger', help: 'Su fecha ya pasó y no se pagaron: es la deuda que hay que cobrar.' },
    { key: 'UPCOMING', label: 'Por vencer', value: result?.counts.UPCOMING ?? null, icon: CalendarClock, tone: 'warning', help: 'Todavía están dentro de su fecha.' },
  ];

  return (
    <Card className="mb-5">
      <div className="px-5 pt-4">
        <h2 className="text-sm font-semibold text-slate-900">Ventas en cuotas</h2>
        <p className="text-[13px] text-slate-500">
          {result ? (
            <>
              Por cobrar <strong className="text-slate-900 tnum">{formatMoney(result.amounts.pending, result.currency)}</strong>
              {Number(result.amounts.overdue) > 0 && (
                <>
                  , de los cuales <strong className="text-rose-700 tnum">{formatMoney(result.amounts.overdue, result.currency)}</strong> ya vencieron
                </>
              )}
              .
            </>
          ) : (
            'Cuotas por cobrar de tus alumnos.'
          )}
        </p>
      </div>
      <div className="px-5 pt-3">
        <FilterCards cards={cards} active={filter} label="Cuotas por situación" onSelect={(key) => setFilter(key as DueFilter)} />
      </div>
      {error && <ErrorState message={error} />}
      {!result && !error && <LoadingState />}
      {result && result.data.length === 0 && <EmptyState icon={CalendarClock} title="Nada por aquí" text="No hay cuotas en esta situación." />}
      {result && result.data.length > 0 && (
        <Table>
          <THead>
            <Th>Vence</Th>
            <Th>Alumno</Th>
            <Th>Compra</Th>
            <Th>Cuota</Th>
            <Th className="text-right">Importe</Th>
            <Th>
              <span className="sr-only">Acciones</span>
            </Th>
          </THead>
          <TBody>
            {result.data.map((due: DueInstallment) => (
              <Tr key={due.id}>
                <Td>
                  <p className="font-medium text-slate-900 whitespace-nowrap">{formatDay(due.due_date)}</p>
                  {due.overdue && <p className="text-xs font-semibold text-rose-700">Vencida hace {due.days_overdue === 1 ? '1 día' : `${due.days_overdue} días`}</p>}
                </Td>
                <Td>
                  <p className="font-medium text-slate-900">{due.order.customer?.name ?? '—'}</p>
                  <p className="text-xs text-slate-500">
                    {due.order.customer?.email}
                    {due.order.customer?.phone ? ` · ${due.order.customer.phone}` : ''}
                  </p>
                </Td>
                <Td>
                  <p className="text-slate-800">{due.order.title}</p>
                  <p className="text-xs text-slate-500 tnum">Orden {orderCode(due.order.number)}</p>
                </Td>
                <Td className="whitespace-nowrap">N.º {due.number}</Td>
                <Td className="text-right font-semibold text-slate-900 tnum whitespace-nowrap">{formatMoney(due.amount, due.order.currency)}</Td>
                <Td className="text-right">
                  <Button size="sm" onClick={() => onOpen(due.order.id)}>
                    Ver orden
                  </Button>
                </Td>
              </Tr>
            ))}
          </TBody>
        </Table>
      )}
    </Card>
  );
};
