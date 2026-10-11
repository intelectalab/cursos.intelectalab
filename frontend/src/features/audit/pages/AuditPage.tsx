import React, { useEffect, useState } from 'react';
import { ChevronDown, ChevronRight, ScrollText } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import {
  Avatar,
  Badge,
  Button,
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
import { SearchSelect } from '../../../shared/ui/SearchSelect';

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
  'enrollments.updated': 'Vigencia de matrícula cambiada',
  'enrollments.cancelled': 'Matrícula cancelada',
  'submissions.graded': 'Tarea calificada',
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
  'auth.login': 'Inicio de sesión',
  'auth.login_failed': 'Intento de acceso fallido',
  'users.registered': 'Cuenta creada desde el registro público',
  'users.sessions_revoked': 'Sesiones de un usuario cerradas',
  'groups.created': 'Grupo creado',
  'grade_scales.created': 'Escala de notas creada',
  'grade_scales.updated': 'Escala de notas editada',
  'grade_scales.deleted': 'Escala de notas eliminada',
  'attempts.granted': 'Intento adicional otorgado',
  'submissions.returned': 'Tarea devuelta para rehacer',
  'orders.created': 'Orden creada',
  'orders.paid': 'Orden pagada',
  'orders.partially_paid': 'Primera cuota pagada: orden en cuotas',
  'orders.cancelled': 'Orden cancelada',
  'orders.expired': 'Orden vencida',
  'orders.refunded': 'Orden reembolsada',
  'orders.installments_set': 'Plan de pagos armado',
  'orders.installments_removed': 'Plan de pagos quitado',
  'payments.confirmed': 'Pago confirmado',
  'payments.online_needs_review': 'Cobro en línea dejado en revisión',
  'payments.online_review_resolved': 'Cobro en revisión resuelto',
  'payment_gateways.connected': 'Pasarela de pago conectada',
  'payment_gateways.disconnected': 'Pasarela de pago desconectada',
  'payment_gateways.enabled': 'Pasarela de pago activada',
  'payment_gateways.disabled': 'Pasarela de pago desactivada',
  'exchange_rates.configured': 'Tipo de cambio configurado',
  'exchange_rates.recorded': 'Tipo de cambio registrado',
  'promotions.created': 'Cupón creado',
  'packages.created': 'Paquete creado',
  'certificates.issued': 'Certificado emitido',
  'certificates.revoked': 'Certificado anulado',
  'certificate_templates.created': 'Plantilla de certificado creada',
  'certificate_templates.updated': 'Plantilla de certificado editada',
  'certificate_templates.deleted': 'Plantilla de certificado eliminada',
  'certificate_templates.layout_updated': 'Diseño del certificado cambiado',
  'certificate_templates.background_updated': 'Fondo del certificado cambiado',
  'certificate_templates.background_removed': 'Fondo del certificado quitado',
  'certificate_templates.signature_updated': 'Firma del certificado cambiada',
  'companies.created': 'Empresa registrada',
  'corporate_members.added': 'Persona agregada a una empresa',
  'leads.converted': 'Prospecto convertido en oportunidad',
  'opportunities.created': 'Oportunidad creada',
  'opportunities.stage_changed': 'Oportunidad movida de etapa',
  'quotations.created': 'Cotización creada',
  'quotations.sent': 'Cotización enviada',
  'quotations.accepted': 'Cotización aceptada',
  'licenses.issued': 'Licencias entregadas',
  'licenses.assigned': 'Licencia asignada',
  'tickets.created': 'Consulta de soporte abierta',
  'tickets.status_changed': 'Consulta de soporte cambió de estado',
  'claims.registered': 'Reclamo registrado',
  'claims.status_changed': 'Reclamo cambió de estado',
  'pages.created': 'Página creada',
  'pages.published': 'Página publicada',
  'pages.deleted': 'Página eliminada',
  'pages.review_requested': 'Página enviada a revisión',
  'pages.changes_requested': 'Página devuelta con observaciones',
  'holidays.national_loaded': 'Feriados nacionales cargados',
  'subscription.order_created': 'Orden de plan pedida',
  'subscription.order_cancelled': 'Orden de plan cancelada',
  'platform.plan_order_confirmed': 'Pago del plan confirmado por IntelectaLab',
  'platform.plan_order_reverted': 'Confirmación del plan deshecha por IntelectaLab',
  'platform.subscription_changed': 'Plan cambiado por IntelectaLab',
  'platform.tenant_updated': 'Datos de la academia editados por IntelectaLab',
  'platform.support_session_started': 'IntelectaLab entró en modo soporte',
};

// Color por familia de acción (el texto siempre acompaña al color)
function actionTone(action: string): Tone {
  if (action.startsWith('access.')) return 'danger';
  if (action.startsWith('auth.')) return 'warning';
  if (action.startsWith('roles.')) return 'violet';
  if (action.startsWith('users.')) return 'brand';
  if (action.startsWith('courses.')) return 'success';
  if (action.startsWith('orders.') || action.startsWith('payments.')) return 'success';
  if (action.startsWith('platform.')) return 'neutral';
  return 'info';
}

const GROUPS = [
  ['', 'Todas las acciones'],
  ['users', 'Usuarios'],
  ['roles', 'Roles'],
  ['auth', 'Accesos y contraseñas'],
  ['courses', 'Cursos'],
  ['enrollments', 'Matrículas'],
  ['orders', 'Órdenes de compra'],
  ['payment', 'Pagos y pasarelas'],
  ['certificate', 'Certificados'],
  ['quotations', 'Cotizaciones'],
  ['licenses', 'Licencias'],
  ['tickets', 'Soporte'],
  ['claims', 'Reclamos'],
  ['pages', 'Páginas'],
  ['tenant', 'Organización'],
  ['platform', 'Hecho por IntelectaLab'],
  ['access', 'Accesos denegados'],
];

// Valor del filtro para lo que no hizo ninguna persona
const SYSTEM = 'system';

interface Actor {
  id: string;
  name: string;
  email: string;
}

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
  const [filters, setFilters] = useState({ action: '', actor_user_id: '', from: '', to: '', page: 1 });
  // Personas que aparecen en el registro; si no cargan, el resto de filtros sigue funcionando
  const [actors, setActors] = useState<Actor[]>([]);
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

  useEffect(() => {
    let active = true;
    api
      .get('/audit-events/actors')
      .then((response) => active && setActors(response.data.data))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  const filtered = filters.action !== '' || filters.actor_user_id !== '' || filters.from !== '' || filters.to !== '';

  const change = (key: 'action' | 'actor_user_id' | 'from' | 'to') => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
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
            <SearchSelect className={`${INPUT_CLASS} mt-1.5`} value={filters.action} onChange={change('action')}>
              {GROUPS.map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </SearchSelect>
          </label>
          <label className="text-[13px] font-medium text-slate-700 min-w-[220px] max-w-xs flex-1">
            Usuario
            <SearchSelect className={`${INPUT_CLASS} mt-1.5`} value={filters.actor_user_id} onChange={change('actor_user_id')}>
              <option value="">Todos los usuarios</option>
              <option value={SYSTEM}>Sistema (sin usuario)</option>
              {actors.map((actor) => (
                <option key={actor.id} value={actor.id}>
                  {actor.name || actor.email} · {actor.email}
                </option>
              ))}
            </SearchSelect>
          </label>
          <label className="text-[13px] font-medium text-slate-700">
            Desde
            <input className={`${INPUT_CLASS} mt-1.5`} type="date" value={filters.from} max={filters.to || undefined} onChange={change('from')} />
          </label>
          <label className="text-[13px] font-medium text-slate-700">
            Hasta
            <input className={`${INPUT_CLASS} mt-1.5`} type="date" value={filters.to} min={filters.from || undefined} onChange={change('to')} />
          </label>
          {filtered && (
            <Button
              onClick={() => {
                setState('loading');
                setFilters({ action: '', actor_user_id: '', from: '', to: '', page: 1 });
              }}
            >
              Quitar filtros
            </Button>
          )}
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
                    <Td className="text-slate-500 pr-0">
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
