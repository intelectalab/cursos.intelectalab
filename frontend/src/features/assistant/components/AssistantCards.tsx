import React from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, ArrowRight, BookOpen, CalendarClock, CheckCircle2, GraduationCap, ListChecks, Package, TrendingUp, Trophy, UsersRound, Video } from 'lucide-react';
import { Badge } from '../../../shared/ui';
import { formatMoney, priceLabel } from '../../sales/api';
import type { Action, Card } from '../answers';
import { assistantApi, type Debt, type EnrollmentLine } from '../api';
import { shouldLearn } from '../search';

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

const DAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
const BLOCK_LABEL = { AVAILABLE: 'Disponible', LIVE_CLASS: 'Clase en vivo', GRADING: 'Revisión de trabajos' } as const;
const formatDate = (value: string) => new Date(value.length === 10 ? `${value}T12:00:00` : value).toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
const grade = (percent: number | null, scale: number) => (percent === null ? null : Math.round(((percent * scale) / 100) * 10) / 10);

const Stat: React.FC<{ label: string; value: React.ReactNode; hint?: string }> = ({ label, value, hint }) => (
  <div className="rounded-xl bg-slate-50 px-3 py-2">
    <p className="text-[11px] text-slate-500">{label}</p>
    <p className="text-base font-bold text-slate-900 tnum">{value}</p>
    {hint && <p className="text-[11px] text-slate-500">{hint}</p>}
  </div>
);

const DebtLines: React.FC<{ debt: Debt[]; empty: string }> = ({ debt, empty }) =>
  debt.length === 0 ? (
    <p className="flex items-center gap-1.5 text-[13px] text-emerald-700">
      <CheckCircle2 className="w-4 h-4" /> {empty}
    </p>
  ) : (
    <ul className="space-y-1 text-[13px]">
      {debt.map((line) => (
        <li key={line.currency}>
          <span className="font-semibold text-slate-900">{formatMoney(line.total, line.currency)}</span>
          <span className="text-slate-600">
            {' '}
            en {line.orders === 1 ? '1 compra' : `${line.orders} compras`}
            {Number(line.overdue) > 0 && <span className="text-rose-700"> · vencido {formatMoney(line.overdue, line.currency)}</span>}
            {line.next_due && ` · próxima cuota el ${formatDate(line.next_due)}`}
          </span>
        </li>
      ))}
    </ul>
  );

const Enrollments: React.FC<{ items: EnrollmentLine[]; scale: number }> = ({ items, scale }) =>
  items.length === 0 ? (
    <p className="text-[13px] text-slate-600">No tiene cursos.</p>
  ) : (
    <ul className="space-y-1.5 text-[13px]">
      {items.slice(0, 6).map((item) => {
        const final = grade(item.final_percent, scale);
        return (
          <li key={item.id} className="flex items-center justify-between gap-2">
            <span className="truncate text-slate-800">{item.course_title}</span>
            <span className="text-xs text-slate-500 whitespace-nowrap tnum">
              {item.state === 'CANCELLED' ? 'Cancelada' : item.state === 'EXPIRED' ? 'Vencida' : item.progress_percent === null ? '—' : `${item.progress_percent} %`}
              {final !== null && ` · nota ${final}`}
            </span>
          </li>
        );
      })}
    </ul>
  );

/** Una respuesta del asistente como tarjeta, con botones que llevan al módulo. */
export const AnswerCard: React.FC<{ card: Card; onAsk: (question: string) => void; onNavigate: () => void; onStudent: (id: string, name: string) => void }> = ({ card, onAsk, onNavigate, onStudent }) => {
  switch (card.kind) {
    case 'course': {
      const { course } = card;
      return (
        <Shell icon={BookOpen} title={course.title}>
          <p className="text-xs text-slate-500 mb-2">
            {course.status === 'PUBLISHED' ? `Publicado${course.version ? `, versión ${course.version}` : ''}` : course.status === 'DRAFT' ? 'Borrador' : 'Archivado'}
            {course.teachers.length > 0 && ` · ${course.teachers.join(', ')}`}
            {course.price !== null && ` · ${priceLabel(course.price, course.currency)}`}
          </p>
          <div className="grid grid-cols-2 gap-2">
            <Stat label="Alumnos con acceso" value={course.students.with_access} hint={`${course.students.completed} lo completaron`} />
            {course.sales ? (
              <Stat label={`Ventas en ${course.sales.days} días`} value={formatMoney(course.sales.revenue, course.currency)} hint={course.sales.units === 1 ? '1 venta' : `${course.sales.units} ventas`} />
            ) : (
              <Stat label="Por vencer (7 días)" value={course.students.expiring} />
            )}
          </div>
          <ul className="mt-2.5 space-y-1 text-[13px] text-slate-700">
            {course.sales && course.students.expiring > 0 && <li>{course.students.expiring} matrículas vencen en los próximos 7 días.</li>}
            {course.pending_submissions !== null && course.pending_submissions > 0 && <li>{course.pending_submissions} tareas esperan nota.</li>}
            {course.sales?.last_sale_at && <li>Última venta: {formatDate(course.sales.last_sale_at)}.</li>}
            {course.rating && (
              <li>
                Reseñas: {course.rating.average.toFixed(1)} de 5 ({course.rating.count}).
              </li>
            )}
            <li>{course.next_class ? `Próxima clase en vivo: ${formatDate(course.next_class.date)}, ${course.next_class.start} con ${course.next_class.teacher}.` : 'Sin clases en vivo en el horario confirmado.'}</li>
          </ul>
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );
    }

    case 'students':
      return (
        <Shell icon={UsersRound} title={`Encontré ${card.students.length} personas con «${card.term}»`}>
          <ul className="space-y-1">
            {card.students.map((student) => (
              <li key={student.id}>
                <button
                  type="button"
                  onClick={() => onStudent(student.id, student.name)}
                  className="w-full text-left rounded-lg px-2 py-1.5 hover:bg-slate-50 cursor-pointer"
                >
                  <span className="block text-[13px] font-semibold text-slate-900">{student.name}</span>
                  <span className="block text-xs text-slate-500">{[student.email, student.document].filter(Boolean).join(' · ')}</span>
                </button>
              </li>
            ))}
          </ul>
        </Shell>
      );

    case 'student': {
      const { context } = card;
      const open = context.tickets.filter((ticket) => ticket.status === 'OPEN' || ticket.status === 'IN_PROGRESS').length;
      return (
        <Shell icon={UsersRound} title={context.student.name}>
          <p className="text-xs text-slate-500 mb-2">
            {context.student.email}
            {context.student.status !== 'ACTIVE' && ' · cuenta inactiva'}
          </p>
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Cursos</p>
          <Enrollments items={context.enrollments} scale={context.grade_scale} />
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mt-3 mb-1">Lo que debe</p>
          <DebtLines debt={context.debt} empty="No debe nada." />
          <p className="text-xs text-slate-600 mt-2.5">
            {context.certificates.filter((certificate) => certificate.status === 'ISSUED').length} certificados
            {open > 0 && ` · ${open} ${open === 1 ? 'consulta abierta' : 'consultas abiertas'}`}
          </p>
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );
    }

    case 'day':
      return (
        <Shell icon={CalendarClock} title={`Horarios del ${DAYS[card.weekday - 1].toLowerCase()}`}>
          {card.rows.length === 0 ? (
            <p className="text-[13px] text-slate-600">Ningún docente tiene bloques confirmados ese día.</p>
          ) : (
            <ul className="space-y-2">
              {card.rows.map((row) => (
                <li key={row.teacher_id}>
                  <p className="text-[13px] font-semibold text-slate-900">{row.teacher}</p>
                  <ul className="mt-0.5 space-y-0.5 text-xs text-slate-600">
                    {row.blocks.map((block, index) => (
                      <li key={index} className="tnum">
                        {block.start}–{block.end} · {block.type === 'LIVE_CLASS' && block.course_title ? `Clase: ${block.course_title}` : BLOCK_LABEL[block.type]}
                      </li>
                    ))}
                  </ul>
                </li>
              ))}
            </ul>
          )}
          <p className="text-[11px] text-slate-500 mt-2">Solo cuentan los horarios que el docente confirmó.</p>
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );

    case 'classes':
      return (
        <Shell icon={Video} title={card.title}>
          {card.classes.length === 0 ? (
            <p className="text-[13px] text-slate-600">No hay clases en vivo en los horarios confirmados.</p>
          ) : (
            <ul className="space-y-1.5 text-[13px]">
              {card.classes.map((item, index) => (
                <li key={index} className="flex flex-wrap items-center justify-between gap-x-3">
                  <span className="text-slate-800">
                    <span className="font-semibold tnum">
                      {DAYS[item.day - 1]} {item.start}–{item.end}
                    </span>
                    {item.course_title && ` · ${item.course_title}`}
                    {item.teacher && ` · ${item.teacher}`}
                  </span>
                  {item.meeting_url && (
                    <a href={item.meeting_url} target="_blank" rel="noreferrer noopener" className="text-xs font-semibold text-brand-700 underline">
                      Entrar
                    </a>
                  )}
                </li>
              ))}
            </ul>
          )}
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );

    case 'mine': {
      const { context } = card;
      return (
        <Shell icon={GraduationCap} title="Lo tuyo">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Tus cursos</p>
          <Enrollments items={context.enrollments} scale={context.grade_scale} />
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mt-3 mb-1">Lo que te falta pagar</p>
          <DebtLines debt={context.debt} empty="No debes nada." />
          {context.live_classes.length > 0 && (
            <p className="text-xs text-slate-600 mt-2.5">
              Próximas clases en vivo: {context.live_classes.slice(0, 2).map((item) => `${DAYS[item.day - 1].toLowerCase()} ${item.start} (${item.course_title})`).join('; ')}.
            </p>
          )}
          <p className="text-xs text-slate-600 mt-1.5">{context.certificates.filter((certificate) => certificate.status === 'ISSUED').length} certificados.</p>
          <Actions actions={card.actions} onNavigate={onNavigate} />
        </Shell>
      );
    }

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
                    <Link
                      to={href}
                      onClick={() => {
                        // Lo buscado no está en el nombre: el buscador aprende el sinónimo
                        if (card.term && shouldLearn(card.term, item.title)) assistantApi.learn(card.term, item.type, item.id);
                        onNavigate();
                      }}
                      className="group flex items-start gap-2"
                    >
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
