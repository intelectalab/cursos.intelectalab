import React, { useCallback, useEffect, useState } from 'react';
import { BookOpen, Building2, ExternalLink, Eye, GraduationCap, Plus, Search, Users } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { tenantUrl } from '../../../shared/tenancy/hosts';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  ConfirmModal,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  PageHeader,
  Pagination,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import { FilterCards, HelpTip, type FilterCard } from '../../../shared/ui/filters';
import { formatMoney } from '../../sales/api';

interface TenantRow {
  id: string;
  name: string;
  slug: string;
  status: 'ACTIVE' | 'SUSPENDED';
  created_at: string | null;
  users_count: number;
  students_count: number;
  published_courses_count: number;
  sales_this_month: string;
  currency: string;
  last_login_at: string | null;
}

interface Totals {
  tenants: number;
  active: number;
  suspended: number;
  users: number;
  students: number;
  published_courses: number;
}

interface Meta {
  page: number;
  last_page: number;
  total: number;
  totals: Totals;
}

const formatWhen = (value: string | null) =>
  value ? new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Nunca';

/* ───────────────────────────── Nueva academia ───────────────────────────── */

const EMPTY = { organization_name: '', subdomain: '', first_name: '', last_name: '', email: '', password: '' };

const NewTenantModal: React.FC<{ centralDomain: string; onClose: () => void; onCreated: (tenant: TenantRow) => void }> = ({ centralDomain, onClose, onCreated }) => {
  const [form, setForm] = useState(EMPTY);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const field = (key: keyof typeof EMPTY) => ({
    className: INPUT_CLASS,
    required: true,
    value: form[key],
    onChange: (event: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: event.target.value }),
  });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      onCreated((await api.post('/platform/tenants', form)).data.data);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo crear la academia.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Nueva academia"
      subtitle="Se crea con sus roles base y su primer administrador, que deberá cambiar la contraseña al entrar."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="new-tenant-form" processing={processing}>
            Crear academia
          </Button>
        </>
      }
    >
      <form id="new-tenant-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Nombre de la academia">
          <input {...field('organization_name')} maxLength={150} autoFocus />
        </Field>
        <Field label="Subdominio" hint={`Su dirección será ${form.subdomain || 'nombre'}.${centralDomain}. Solo minúsculas, números y guiones.`}>
          <input {...field('subdomain')} minLength={3} maxLength={40} pattern="[a-z0-9]+(-[a-z0-9]+)*" />
        </Field>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="Nombres del administrador">
            <input {...field('first_name')} maxLength={100} />
          </Field>
          <Field label="Apellidos">
            <input {...field('last_name')} maxLength={100} />
          </Field>
        </div>
        <Field label="Correo del administrador">
          <input {...field('email')} type="email" maxLength={180} />
        </Field>
        <Field label="Contraseña temporal" hint="Mínimo 8 caracteres. Entrégasela por un medio seguro: al entrar tendrá que cambiarla.">
          <input {...field('password')} minLength={8} autoComplete="off" />
        </Field>
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Panel ───────────────────────────── */

type Dialog = { kind: 'new' } | { kind: 'suspend' | 'support'; tenant: TenantRow };

// Todas las academias de la plataforma: sus cifras, su alta, su estado y el acceso de soporte
export const TenantsPanel: React.FC = () => {
  const { centralDomain } = useTenant();
  const [rows, setRows] = useState<TenantRow[]>([]);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [processing, setProcessing] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await api.get('/platform/tenants', { params: { search: search || undefined, status: status || undefined, page } });
      setRows(response.data.data);
      setMeta(response.data.meta);
      setState('ready');
    } catch (err) {
      setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, page]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };

  const setTenantStatus = async (tenant: TenantRow, next: TenantRow['status']) => {
    setProcessing(tenant.id);
    setNotice(null);
    try {
      await api.patch(`/platform/tenants/${tenant.id}/status`, { status: next });
      await load();
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo cambiar el estado de la academia.') });
    } finally {
      setProcessing(null);
    }
  };

  const openSupport = async (tenant: TenantRow) => {
    const response = await api.post(`/platform/tenants/${tenant.id}/support-session`);
    const { token, slug, as_user: asUser } = response.data.data as { token: string; slug: string; as_user: string };
    // El acceso viaja en el fragmento de la dirección: no llega a ningún servidor ni queda en sus registros
    window.open(`${tenantUrl(slug, centralDomain, '/support-access')}#token=${encodeURIComponent(token)}`, '_blank', 'noopener');
    setNotice({ tone: 'success', text: `Se abrió «${tenant.name}» en otra pestaña, en solo lectura, con la vista de ${asUser}. El acceso dura 30 minutos y quedó en la auditoría de la academia.` });
    setDialog(null);
  };

  const totals = meta?.totals;
  const cards: FilterCard[] = [
    { key: '', label: 'Academias', value: totals?.tenants ?? null, icon: Building2, tone: 'brand', help: 'Todas las academias registradas en la plataforma.' },
    { key: 'ACTIVE', label: 'Activas', value: totals?.active ?? null, icon: Building2, tone: 'success', help: 'Sus usuarios pueden entrar y trabajar con normalidad.' },
    { key: 'SUSPENDED', label: 'Suspendidas', value: totals?.suspended ?? null, icon: Building2, tone: 'warning', help: 'Nadie de la academia puede entrar hasta que se reactive. Sus datos se conservan.' },
  ];

  return (
    <>
      <PageHeader
        icon={Building2}
        title="Academias"
        subtitle="Todas las academias de la plataforma, con sus cifras de hoy."
        action={
          <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'new' })}>
            Nueva academia
          </Button>
        }
      />

      {notice && (
        <div className="mb-4">
          <Alert tone={notice.tone}>{notice.text}</Alert>
        </div>
      )}

      <FilterCards cards={cards} active={status} label="Academias por estado" onSelect={(key) => filter(() => setStatus(key))} />

      {totals && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
          {(
            [
              { label: 'Usuarios activos en toda la plataforma', value: totals.users, icon: Users },
              { label: 'Alumnos con matrícula activa', value: totals.students, icon: GraduationCap },
              { label: 'Cursos publicados', value: totals.published_courses, icon: BookOpen },
            ] as const
          ).map(({ label, value, icon: Icon }) => (
            <div key={label} className="flex items-center gap-3 px-4 py-3 bg-white border border-slate-200/80 rounded-2xl shadow-card">
              <span className="w-9 h-9 shrink-0 rounded-xl bg-brand-50 text-brand-700 inline-flex items-center justify-center">
                <Icon className="w-[18px] h-[18px]" />
              </span>
              <span>
                <span className="block text-lg leading-6 font-bold text-slate-900 tnum">{value}</span>
                <span className="block text-xs text-slate-600">{label}</span>
              </span>
            </div>
          ))}
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por nombre o subdominio"
              aria-label="Buscar academia"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={loadError} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState icon={Building2} title={search || status ? 'Ninguna academia coincide' : 'Aún no hay academias'} text={search || status ? 'Prueba con otra búsqueda o quita el filtro.' : undefined} />
        )}

        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Academia</Th>
              <Th>Usuarios</Th>
              <Th>Alumnos</Th>
              <Th>Cursos</Th>
              <Th>
                <span className="inline-flex items-center gap-1">
                  Ventas del mes
                  <HelpTip text="Suma de las órdenes pagadas desde el día 1 de este mes, en la moneda de cada academia." label="Qué son las ventas del mes" />
                </span>
              </Th>
              <Th>Último acceso</Th>
              <Th>Estado</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((tenant) => (
                <Tr key={tenant.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={tenant.name} />
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-900 whitespace-nowrap">{tenant.name}</p>
                        <a
                          href={tenantUrl(tenant.slug, centralDomain, '/login')}
                          target="_blank"
                          rel="noreferrer"
                          className="inline-flex items-center gap-1 text-xs text-brand-600 hover:text-brand-800"
                        >
                          {tenant.slug}.{centralDomain}
                          <ExternalLink className="w-3 h-3" />
                        </a>
                      </div>
                    </div>
                  </Td>
                  <Td className="text-slate-700 tnum">{tenant.users_count}</Td>
                  <Td className="text-slate-700 tnum">{tenant.students_count}</Td>
                  <Td className="text-slate-700 tnum">{tenant.published_courses_count}</Td>
                  <Td className="whitespace-nowrap font-medium text-slate-900 tnum">{formatMoney(tenant.sales_this_month, tenant.currency)}</Td>
                  <Td className="whitespace-nowrap text-slate-600 tnum">{formatWhen(tenant.last_login_at)}</Td>
                  <Td>
                    <Badge tone={tenant.status === 'ACTIVE' ? 'success' : 'warning'} dot>
                      {tenant.status === 'ACTIVE' ? 'Activa' : 'Suspendida'}
                    </Badge>
                  </Td>
                  <Td className="text-right whitespace-nowrap">
                    <div className="inline-flex gap-1.5">
                      {tenant.status === 'ACTIVE' ? (
                        <>
                          <Button size="sm" icon={<Eye className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'support', tenant })}>
                            Entrar
                          </Button>
                          <Button size="sm" variant="danger" disabled={processing !== null} onClick={() => setDialog({ kind: 'suspend', tenant })}>
                            Suspender
                          </Button>
                        </>
                      ) : (
                        <Button size="sm" processing={processing === tenant.id} disabled={processing !== null} onClick={() => setTenantStatus(tenant, 'ACTIVE')}>
                          Reactivar
                        </Button>
                      )}
                    </div>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}

        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="academias" onPage={setPage} />}
      </Card>

      {dialog?.kind === 'new' && (
        <NewTenantModal
          centralDomain={centralDomain}
          onClose={() => setDialog(null)}
          onCreated={async (tenant) => {
            setDialog(null);
            setNotice({ tone: 'success', text: `Se creó «${tenant.name}» en ${tenant.slug}.${centralDomain}. Entrégale la contraseña temporal a su administrador.` });
            await load();
          }}
        />
      )}
      {dialog?.kind === 'suspend' && (
        <ConfirmModal
          title="Suspender academia"
          text={`Nadie de «${dialog.tenant.name}» podrá entrar hasta que la reactives: ni su personal ni sus alumnos. Sus datos se conservan.`}
          confirmLabel="Suspender"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await setTenantStatus(dialog.tenant, 'SUSPENDED');
            setDialog(null);
          }}
        />
      )}
      {dialog?.kind === 'support' && (
        <ConfirmModal
          title="Entrar en modo soporte"
          text={`Se abrirá «${dialog.tenant.name}» en otra pestaña con lo que ve su administrador, en solo lectura y durante 30 minutos. No podrás cambiar nada, y el acceso queda registrado en la auditoría de la academia, a la vista de su administrador.`}
          confirmLabel="Entrar en solo lectura"
          variant="primary"
          onClose={() => setDialog(null)}
          onConfirm={() => openSupport(dialog.tenant)}
        />
      )}
    </>
  );
};
