import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarCheck2, CalendarClock, CalendarDays, CalendarX2, MessageSquareWarning, Search, Users } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Avatar, Badge, Card, EmptyState, ErrorState, INPUT_CLASS, LoadingState, PageHeader, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { FilterCards, type FilterCard } from '../../../shared/ui/filters';
import { BLOCK_TYPES, DAYS, formatHours, schedulesApi, STATUS_META, type ScheduleFilter, type ScheduleRow } from '../api';

// Lunes = 1 … domingo = 7, como en el horario
const today = () => ((new Date().getDay() + 6) % 7) + 1;

export const SchedulesPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('schedules.manage');

  const [rows, setRows] = useState<ScheduleRow[]>([]);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [timezone, setTimezone] = useState('');
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<ScheduleFilter>('');
  const [view, setView] = useState<'teachers' | 'day'>('teachers');
  const [day, setDay] = useState(today);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');

  const load = useCallback(async () => {
    setState('loading');
    try {
      const result = await schedulesApi.list({ search: search || undefined, filter: filter || undefined });
      setRows(result.data);
      setCounts(result.counts);
      setTimezone(result.timezone);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, filter]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const cards: FilterCard[] = [
    { key: '', label: 'Docentes', value: counts.ALL ?? null, icon: Users, tone: 'brand', help: 'Docentes con la cuenta activa, según la búsqueda.' },
    { key: 'PENDING', label: 'Por confirmar', value: counts.PENDING ?? null, icon: CalendarClock, tone: 'warning', help: 'Se les envió un horario y todavía no lo confirman.' },
    { key: 'CONFIRMED', label: 'Confirmados', value: counts.CONFIRMED ?? null, icon: CalendarCheck2, tone: 'success', help: 'El docente confirmó su horario. Sus clases en vivo ya las ven los alumnos.' },
    { key: 'OBSERVED', label: 'Con observaciones', value: counts.OBSERVED ?? null, icon: MessageSquareWarning, tone: 'danger', help: 'El docente pidió cambios. Ábrelo para leer qué pide y enviarle otro.' },
    { key: 'NONE', label: 'Sin horario', value: counts.NONE ?? null, icon: CalendarX2, tone: 'neutral', help: 'Todavía no se les armó un horario.' },
  ];

  const dayRows = rows
    .map((row) => ({ row, blocks: (row.schedule?.blocks ?? []).filter((block) => block.day === day) }))
    .filter((item) => item.blocks.length > 0);

  const tab = (key: typeof view, label: string) => (
    <button
      type="button"
      role="tab"
      aria-selected={view === key}
      onClick={() => setView(key)}
      className={`h-9 px-3.5 rounded-lg text-[13px] font-semibold cursor-pointer transition-colors ${view === key ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
    >
      {label}
    </button>
  );

  return (
    <div>
      <PageHeader
        title="Horarios"
        icon={CalendarDays}
        subtitle={`A qué hora está disponible cada docente, cuándo da sus clases en vivo y cuándo revisa trabajos. ${canManage ? 'Tú lo armas y el docente lo confirma.' : ''}`}
      />

      <FilterCards cards={cards} active={filter} label="Docentes por estado de su horario" onSelect={(key) => setFilter(key as ScheduleFilter)} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div role="tablist" aria-label="Forma de ver los horarios" className="flex gap-1">
            {tab('teachers', 'Por docente')}
            {tab('day', 'Por día')}
          </div>
          {view === 'day' && (
            <select className={`${INPUT_CLASS} w-auto`} aria-label="Día" value={day} onChange={(event) => setDay(Number(event.target.value))}>
              {DAYS.map((name, index) => (
                <option key={name} value={index + 1}>
                  {name}
                </option>
              ))}
            </select>
          )}
          <div className="relative flex-1 min-w-[200px] max-w-sm ml-auto">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input className={`${INPUT_CLASS} pl-9`} placeholder="Buscar docente" aria-label="Buscar docente" value={search} onChange={(event) => setSearch(event.target.value)} />
          </div>
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}

        {state === 'ready' && view === 'teachers' && rows.length === 0 && (
          <EmptyState icon={CalendarDays} title="Sin docentes que mostrar" text="Prueba con otra búsqueda o quita el filtro. Los docentes se crean en «Docentes»." />
        )}
        {state === 'ready' && view === 'teachers' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Docente</Th>
              <Th>Estado</Th>
              <Th>Horas por semana</Th>
              <Th>Desde</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((row) => (
                <Tr key={row.teacher.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={row.teacher.name} size="sm" />
                      <div className="min-w-0">
                        <Link to={`/schedules/${row.teacher.id}`} className="font-semibold text-slate-900 hover:text-brand-700 whitespace-nowrap">
                          {row.teacher.name}
                        </Link>
                        <p className="text-xs text-slate-500">{row.courses_count === 1 ? '1 curso' : `${row.courses_count} cursos`}</p>
                      </div>
                    </div>
                  </Td>
                  <Td>
                    {row.schedule ? (
                      <Badge tone={STATUS_META[row.schedule.status].tone} dot>
                        {STATUS_META[row.schedule.status].label}
                      </Badge>
                    ) : (
                      <span className="text-slate-500">Sin horario</span>
                    )}
                  </Td>
                  <Td>
                    {row.schedule ? (
                      <div className="flex flex-wrap gap-1.5">
                        {(Object.keys(BLOCK_TYPES) as (keyof typeof BLOCK_TYPES)[])
                          .filter((type) => row.hours[type] > 0)
                          .map((type) => (
                            <Badge key={type} tone={BLOCK_TYPES[type].tone}>
                              {BLOCK_TYPES[type].label}: {formatHours(row.hours[type])}
                            </Badge>
                          ))}
                      </div>
                    ) : (
                      <span className="text-slate-500">—</span>
                    )}
                  </Td>
                  <Td className="whitespace-nowrap text-slate-600 tnum">{row.schedule ? new Date(`${row.schedule.valid_from}T12:00:00`).toLocaleDateString('es-PE') : '—'}</Td>
                  <Td className="text-right">
                    <Link
                      to={`/schedules/${row.teacher.id}`}
                      className="inline-flex items-center h-8 px-3 rounded-lg border border-slate-300 bg-white text-[13px] font-semibold text-slate-700 hover:bg-slate-50 whitespace-nowrap"
                    >
                      {canManage ? (row.schedule ? 'Editar horario' : 'Armar horario') : 'Ver horario'}
                    </Link>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}

        {state === 'ready' && view === 'day' && dayRows.length === 0 && (
          <EmptyState icon={CalendarDays} title={`Nadie tiene bloques el ${DAYS[day - 1].toLowerCase()}`} text="Elige otro día o arma los horarios desde la vista «Por docente»." />
        )}
        {state === 'ready' && view === 'day' && dayRows.length > 0 && (
          <ul className="divide-y divide-slate-100">
            {dayRows.map(({ row, blocks }) => (
              <li key={row.teacher.id} className="flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-3.5">
                <Link to={`/schedules/${row.teacher.id}`} className="sm:w-56 shrink-0 flex items-center gap-3 font-semibold text-slate-900 hover:text-brand-700">
                  <Avatar name={row.teacher.name} size="sm" />
                  <span className="min-w-0">
                    {row.teacher.name}
                    {row.schedule?.status !== 'CONFIRMED' && (
                      <span className="block text-xs font-normal text-slate-500">{row.schedule ? STATUS_META[row.schedule.status].label : ''}</span>
                    )}
                  </span>
                </Link>
                <div className="flex flex-wrap gap-2">
                  {blocks.map((block, index) => (
                    <span key={`${block.start}-${block.type}-${index}`} className={`rounded-lg border px-2.5 py-1.5 text-xs ${BLOCK_TYPES[block.type].block}`}>
                      <span className="font-semibold tnum">
                        {block.start}–{block.end}
                      </span>{' '}
                      · {block.type === 'LIVE_CLASS' && block.course_title ? `Clase: ${block.course_title}` : BLOCK_TYPES[block.type].label}
                    </span>
                  ))}
                </div>
              </li>
            ))}
          </ul>
        )}

        {state === 'ready' && timezone && <p className="px-5 py-3 border-t border-slate-200/80 text-xs text-slate-500">Horas de la academia ({timezone}).</p>}
      </Card>
    </div>
  );
};
