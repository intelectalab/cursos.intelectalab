import React, { useEffect, useState } from 'react';
import { ChevronDown, ChevronRight, ScrollText } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import {
  Avatar,
  Badge,
  Card,
  EmptyState,
  ErrorState,
  INPUT_CLASS,
  LoadingState,
  PageHeader,
  Pagination,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
  type Tone,
} from '../../../shared/ui';

interface AuditRow {
  id: string;
  action: string;
  entity_type: string;
  entity_id: string | null;
  actor: { id: string; name: string; email: string } | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  ip_address: string | null;
  request_id: string | null;
  created_at: string;
}

interface Meta {
  page: number;
  last_page: number;
  total: number;
}

// Texto legible de cada acción registrada
const ACTION_LABEL: Record<string, string> = {
  'tenants.provisioned': 'Organización creada',
  'tenants.status_changed': 'Estado de la organización cambiado',
  'tenant_settings.updated': 'Configuración actualizada',
  'tenant_settings.logo_updated': 'Logo actualizado',
  'tenant_settings.logo_removed': 'Logo eliminado',
  'courses.created': 'Curso creado',
  'courses.updated': 'Curso editado',
  'courses.published': 'Curso publicado',
  'courses.archived': 'Curso archivado',
  'courses.restored': 'Curso restaurado',
  'courses.teachers_updated': 'Profesores del curso cambiados',
  'enrollments.created': 'Alumno matriculado',
  'enrollments.cancelled': 'Matrícula cancelada',
  'users.created': 'Usuario creado',
  'users.updated': 'Usuario editado',
  'users.roles_updated': 'Roles de usuario cambiados',
  'roles.created': 'Rol creado',
  'roles.updated': 'Rol editado',
  'roles.deleted': 'Rol eliminado',
  'auth.password_reset_requested': 'Recuperación de contraseña solicitada',
  'auth.password_reset': 'Contraseña restablecida',
  'auth.password_changed': 'Contraseña cambiada',
  'access.denied': 'Acceso denegado',
};

// Color por familia de acción (el texto siempre acompaña al color)
function actionTone(action: string): Tone {
  if (action.startsWith('access.')) return 'danger';
  if (action.startsWith('auth.')) return 'warning';
  if (action.startsWith('roles.')) return 'violet';
  if (action.startsWith('users.')) return 'brand';
  if (action.startsWith('courses.')) return 'success';
  return 'info';
}

const GROUPS = [
  ['', 'Todas las acciones'],
  ['users', 'Usuarios'],
  ['roles', 'Roles'],
  ['auth', 'Contraseñas'],
  ['courses', 'Cursos'],
  ['enrollments', 'Matrículas'],
  ['tenant', 'Organización'],
  ['access', 'Accesos denegados'],
];

const formatDate = (value: string) =>
  new Date(value).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'medium' });

const Json: React.FC<{ title: string; value: Record<string, unknown> | null }> = ({ title, value }) => (
  <div>
    <p className="text-xs font-semibold text-slate-500 mb-1.5">{title}</p>
    <pre className="text-xs leading-relaxed text-slate-700 bg-white border border-slate-200 rounded-xl p-3 overflow-x-auto whitespace-pre-wrap break-words">
      {value ? JSON.stringify(value, null, 2) : '—'}
    </pre>
  </div>
);

export const AuditPage: React.FC = () => {
  const [filters, setFilters] = useState({ action: '', from: '', to: '', page: 1 });
  const [rows, setRows] = useState<AuditRow[]>([]);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

    api
      .get('/audit-events', { params })
      .then((response) => {
        if (!active) return;
        setRows(response.data.data);
        setMeta(response.data.meta);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });

    return () => {
      active = false;
    };
  }, [filters, attempt]);

  const change = (key: 'action' | 'from' | 'to') => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setState('loading');
    setFilters({ ...filters, [key]: event.target.value, page: 1 });
  };

  const goTo = (page: number) => {
    setState('loading');
    setFilters({ ...filters, page });
  };

  return (
    <div>
      <PageHeader
        title="Auditoría"
        icon={ScrollText}
        subtitle="Registro de las operaciones sensibles de tu organización. No se puede modificar ni eliminar."
      />

      <Card>
        <div className="flex flex-wrap items-end gap-3 px-5 py-4 border-b border-slate-200/80">
          <label className="text-[13px] font-medium text-slate-700">
            Tipo de acción
            <select className={`${INPUT_CLASS} mt-1.5`} value={filters.action} onChange={change('action')}>
              {GROUPS.map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </label>
          <label className="text-[13px] font-medium text-slate-700">
            Desde
            <input className={`${INPUT_CLASS} mt-1.5`} type="date" value={filters.from} max={filters.to || undefined} onChange={change('from')} />
          </label>
          <label className="text-[13px] font-medium text-slate-700">
            Hasta
            <input className={`${INPUT_CLASS} mt-1.5`} type="date" value={filters.to} min={filters.from || undefined} onChange={change('to')} />
          </label>
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && (
          <ErrorState
            message={loadError}
            onRetry={() => {
              setState('loading');
              setAttempt((n) => n + 1);
            }}
          />
        )}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState icon={ScrollText} title="Sin eventos" text="No hay registros para los filtros seleccionados." />
        )}

        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th className="w-10" />
              <Th>Fecha</Th>
              <Th>Acción</Th>
              <Th>Realizada por</Th>
              <Th>IP</Th>
            </THead>
            <TBody>
              {rows.map((row) => (
                <React.Fragment key={row.id}>
                  <Tr className="cursor-pointer" onClick={() => setOpen(open === row.id ? null : row.id)}>
                    <Td className="text-slate-400 pr-0">
                      <button type="button" aria-label="Ver detalle" aria-expanded={open === row.id} className="cursor-pointer">
                        {open === row.id ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
                      </button>
                    </Td>
                    <Td className="text-slate-600 whitespace-nowrap tnum">{formatDate(row.created_at)}</Td>
                    <Td>
                      <Badge tone={actionTone(row.action)} dot>
                        {ACTION_LABEL[row.action] ?? row.action}
                      </Badge>
                    </Td>
                    <Td>
                      {row.actor ? (
                        <div className="flex items-center gap-2.5">
                          <Avatar name={row.actor.name} size="sm" />
                          <div className="min-w-0">
                            <p className="font-medium text-slate-900 truncate">{row.actor.name}</p>
                            <p className="text-xs text-slate-500 truncate">{row.actor.email}</p>
                          </div>
                        </div>
                      ) : (
                        <span className="text-slate-500">Sistema</span>
                      )}
                    </Td>
                    <Td className="text-slate-500 tnum">{row.ip_address ?? '—'}</Td>
                  </Tr>
                  {open === row.id && (
                    <tr className="bg-slate-50/70">
                      <td />
                      <td colSpan={4} className="px-5 py-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                          <Json title="Antes" value={row.before} />
                          <Json title="Después" value={row.after} />
                        </div>
                        <p className="text-xs text-slate-500 mt-3">
                          Acción <code className="font-mono text-slate-700">{row.action}</code> · Entidad{' '}
                          {row.entity_type} {row.entity_id ?? ''} · Solicitud {row.request_id ?? '—'}
                        </p>
                      </td>
                    </tr>
                  )}
                </React.Fragment>
              ))}
            </TBody>
          </Table>
        )}

        {state === 'ready' && meta && (
          <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="eventos" onPage={goTo} />
        )}
      </Card>
    </div>
  );
};
