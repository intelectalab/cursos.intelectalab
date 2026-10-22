import React from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, ArrowRight, BookOpen, CalendarClock, CheckCircle2, ListChecks, Package, TrendingUp, Trophy, UsersRound } from 'lucide-react';
import { Badge } from '../../../shared/ui';
import { formatMoney, priceLabel } from '../../sales/api';
import type { Action, Card } from '../answers';

const Actions: React.FC<{ actions?: Action[]; onNavigate: () => void }> = ({ actions, onNavigate }) =>
  actions && actions.length > 0 ? (
    <div className="flex flex-wrap gap-1.5 mt-3">
      {actions.map((action) => (
        <Link
          key={action.href + action.label}
          to={action.href}
          onClick={onNavigate}
          className="inline-flex items-center gap-1 h-7 px-2.5 rounded-lg border border-brand-200 bg-brand-50 text-xs font-semibold text-brand-700 hover:bg-brand-100"
        >
          {action.label} <ArrowRight className="w-3 h-3" />
        </Link>
      ))}
    </div>
  ) : null;

const Shell: React.FC<{ icon: typeof BookOpen; title: string; children: React.ReactNode }> = ({ icon: Icon, title, children }) => (
  <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
    <p className="flex items-center gap-2 text-[13px] font-semibold text-slate-900 mb-2">
      <span className="w-6 h-6 rounded-lg bg-brand-50 text-brand-700 flex items-center justify-center">
        <Icon className="w-3.5 h-3.5" />
      </span>
      {title}
    </p>
    {children}
  </div>
);

/** Una respuesta del asistente como tarjeta, con botones que llevan al módulo. */
export const AnswerCard: React.FC<{ card: Card; onAsk: (question: string) => void; onNavigate: () => void }> = ({ card, onAsk, onNavigate }) => {
  switch (card.kind) {
    case 'text':
      return (
        <div
          className={`rounded-2xl border p-3.5 text-[13px] ${
            card.tone === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : card.tone === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-slate-200 bg-white text-slate-700'
          }`}
        >
          {card.title && <p className="font-semibold text-slate-900 mb-1">{card.title}</p>}
          <p>{card.text}</p>
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </div>
      );

    case 'help':
      return (
        <Shell icon={ListChecks} title="Te puedo ayudar con">
          <ul className="list-disc pl-5 space-y-1 text-[13px] text-slate-700">
            {card.capabilities.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
          <p className="text-xs text-slate-500 mt-2.5">Respondo con los datos de tu academia que tu rol puede ver. No cambio nada.</p>
          <div className="flex flex-wrap gap-1.5 mt-2.5">
            {card.prompts.map((prompt) => (
              <button key={prompt} type="button" onClick={() => onAsk(prompt)} className="h-7 px-2.5 rounded-full border border-slate-300 bg-white text-xs text-slate-700 hover:bg-slate-50 cursor-pointer">
                {prompt}
              </button>
            ))}
          </div>
        </Shell>
      );

    case 'guides':
      return (
        <div className="space-y-2.5">
          {card.guides.map((guide) => (
            <Shell key={guide.title} icon={ListChecks} title={guide.title}>
              <ol className="list-decimal pl-5 space-y-1 text-[13px] text-slate-700">
                {guide.steps.map((step) => (
                  <li key={step}>{step}</li>
                ))}
              </ol>
              <Actions actions={guide.link && (!guide.link.permission || card.can(guide.link.permission)) ? [guide.link] : []} onNavigate={onNavigate} />
            </Shell>
          ))}
        </div>
      );

    case 'sales':
      return (
        <Shell icon={TrendingUp} title={`Ventas · ${card.period}`}>
          <div className="grid grid-cols-2 gap-2">
            <div className="rounded-xl bg-slate-50 px-3 py-2">
              <p className="text-[11px] text-slate-500">Cobrado</p>
              <p className="text-base font-bold text-slate-900 tnum">{formatMoney(card.sales.revenue, card.currency)}</p>
              <p className="text-[11px] text-slate-500">{card.sales.payments === 1 ? '1 pago' : `${card.sales.payments} pagos`}</p>
            </div>
            <div className="rounded-xl bg-slate-50 px-3 py-2">
              <p className="text-[11px] text-slate-500">Ventas hechas</p>
              <p className="text-base font-bold text-slate-900 tnum">{card.sales.orders}</p>
              <p className="text-[11px] text-slate-500">órdenes</p>
            </div>
          </div>
          {card.sales.top.length > 0 ? (
            <ul className="mt-2.5 space-y-1 text-[13px]">
              {card.sales.top.slice(0, 3).map((item) => (
                <li key={item.course_id} className="flex justify-between gap-2">
                  <span className="truncate text-slate-700">{item.title}</span>
                  <span className="tnum text-slate-500 whitespace-nowrap">{formatMoney(item.revenue, card.currency)}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-xs text-slate-500 mt-2.5">No hubo ventas en este periodo.</p>
          )}
          <p className="text-[11px] text-slate-500 mt-2">«Cobrado» suma cada pago confirmado (cada cuota el día en que entra); no cuenta reembolsos.</p>
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );

    case 'top':
      return (
        <Shell icon={Trophy} title={`Lo más vendido · ${card.period}`}>
          {card.top.length === 0 ? (
            <p className="text-[13px] text-slate-600">No hubo ventas en este periodo.</p>
          ) : (
            <ol className="space-y-1.5 text-[13px]">
              {card.top.map((item, index) => (
                <li key={item.course_id} className="flex items-center gap-2">
                  <span className="w-5 h-5 rounded-full bg-brand-50 text-brand-700 text-[11px] font-bold flex items-center justify-center shrink-0">{index + 1}</span>
                  {card.courseLink ? (
                    <Link to={`/courses/${item.course_id}`} onClick={onNavigate} className="flex-1 min-w-0 truncate font-medium text-slate-800 hover:text-brand-700">
                      {item.title}
                    </Link>
                  ) : (
                    <span className="flex-1 min-w-0 truncate font-medium text-slate-800">{item.title}</span>
                  )}
                  <span className="tnum text-xs text-slate-500 whitespace-nowrap">
                    {item.units} · {formatMoney(item.revenue, card.currency)}
                  </span>
                </li>
              ))}
            </ol>
          )}
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );

    case 'enrollments':
      return (
        <Shell icon={UsersRound} title={`Matrículas nuevas · ${card.period}`}>
          <p className="text-2xl font-bold text-slate-900 tnum">{card.enrollments.new}</p>
          {card.enrollments.scope === 'assigned' && <p className="text-xs text-slate-500">En tus cursos.</p>}
          {card.enrollments.by_course.length > 0 && (
            <ul className="mt-2 space-y-1 text-[13px]">
              {card.enrollments.by_course.map((item) => (
                <li key={item.course_id} className="flex justify-between gap-2">
                  <span className="truncate text-slate-700">{item.title}</span>
                  <span className="tnum text-slate-500">{item.total}</span>
                </li>
              ))}
            </ul>
          )}
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );

    case 'pending': {
      const plan = card.plan;
      return (
        <Shell icon={CalendarClock} title="Pendientes">
          {card.lines.length === 0 ? (
            <p className="flex items-center gap-2 text-[13px] text-emerald-700">
              <CheckCircle2 className="w-4 h-4" /> Todo al día: no hay nada esperando tu atención.
            </p>
          ) : (
            <ul className="space-y-1.5">
              {card.lines.map((line) => (
                <li key={line.key} className="flex items-center justify-between gap-2 text-[13px]">
                  <span className="flex items-center gap-2 text-slate-700">
                    <AlertCircle className="w-3.5 h-3.5 text-amber-600 shrink-0" />
                    {line.text}
                  </span>
                  {line.href && (
                    <Link to={line.href} onClick={onNavigate} className="text-xs font-semibold text-brand-700 hover:underline whitespace-nowrap">
                      Ver
                    </Link>
                  )}
                </li>
              ))}
            </ul>
          )}
          {plan && (
            <div className="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
              <p className="font-semibold text-slate-800">Tu plan{plan.plan ? `: ${plan.plan.name}` : ''}</p>
              <p>
                {plan.max_students === null ? `${plan.students} alumnos, sin tope.` : `${plan.students} de ${plan.max_students} alumnos; quedan ${plan.seats_left}.`}{' '}
                {plan.access === 'READ_ONLY'
                  ? 'Está vencido: la academia está en solo lectura.'
                  : plan.access === 'GRACE'
                    ? `Venció: quedan ${plan.grace_days_left} días de gracia.`
                    : plan.days_left !== null
                      ? `Vence en ${plan.days_left} ${plan.days_left === 1 ? 'día' : 'días'}.`
                      : ''}
              </p>
            </div>
          )}
        </Shell>
      );
    }

    case 'results': {
      const { catalog } = card;
      return (
        <Shell icon={BookOpen} title={card.term ? `Resultados para «${card.term}»` : 'Cursos y paquetes'}>
          {card.results.length === 0 ? (
            <p className="text-[13px] text-slate-600">No encontré cursos ni paquetes con «{card.term}». Prueba con otra palabra o con parte del nombre.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {card.results.map(({ item }) => {
                const href = catalog.staff
                  ? item.type === 'COURSE'
                    ? `/courses/${item.id}`
                    : '/promotions'
                  : item.type === 'COURSE'
                    ? `/catalog/${item.id}`
                    : `/catalog/packages/${item.id}`;
                const price = priceLabel(item.price, catalog.currency);
                return (
                  <li key={item.type + item.id} className="py-2 first:pt-0 last:pb-0">
                    <Link to={href} onClick={onNavigate} className="group flex items-start gap-2">
                      {item.type === 'PACKAGE' ? <Package className="w-4 h-4 mt-0.5 text-violet-600 shrink-0" /> : <BookOpen className="w-4 h-4 mt-0.5 text-brand-600 shrink-0" />}
                      <span className="flex-1 min-w-0">
                        <span className="block text-[13px] font-semibold text-slate-900 group-hover:text-brand-700">{item.title}</span>
                        <span className="block text-xs text-slate-500">
                          {[item.type === 'PACKAGE' ? 'Paquete' : item.category, price ?? (catalog.staff ? 'No está a la venta' : null)].filter(Boolean).join(' · ')}
                        </span>
                      </span>
                      {catalog.staff && item.status !== 'PUBLISHED' && item.status !== 'ACTIVE' && <Badge tone="neutral">{item.status === 'DRAFT' ? 'Borrador' : 'Inactivo'}</Badge>}
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
          {card.note && <p className="text-xs text-slate-500 mt-2">{card.note}</p>}
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );
    }
  }
};
