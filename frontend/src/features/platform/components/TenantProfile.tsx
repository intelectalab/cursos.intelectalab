import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Award, BookOpen, ClipboardList, ExternalLink, Eye, GraduationCap, HardDrive, KeyRound, Receipt, Users } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { tenantUrl } from '../../../shared/tenancy/hosts';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  CardHeader,
  ConfirmModal,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  StatCard,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import { formatSize } from '../../courses/api';
import { formatMoney } from '../../sales/api';
import { platformApi, type TenantAdmin, type TenantData, type TenantHistoryEvent, type TenantProfile as Profile } from '../api';
import { SubscriptionCard } from './SubscriptionCard';
import { SuspendTenantModal } from './SuspendTenantModal';

const formatWhen = (value: string | null) =>
  value ? new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Nunca';

const formatMonth = (month: string) => {
  const label = new Date(`${month}-15T12:00:00`).toLocaleDateString('es-PE', { month: 'short', year: '2-digit' });
  return label.charAt(0).toUpperCase() + label.slice(1);
};

const FIELD_LABEL: Record<keyof TenantData, string> = {
  name: 'nombre',
  legal_name: 'razón social',
  tax_id: 'RUC',
  contact_email: 'correo',
  contact_phone: 'teléfono',
  contact_address: 'dirección',
};

// Una línea legible por cada cosa que la plataforma hizo sobre la academia
function describe(event: TenantHistoryEvent): { title: string; detail: string | null; tone: 'info' | 'warning' | 'success' | 'neutral' } {
  const after = event.after ?? {};
  const who = after.platform_admin ? ` · ${after.platform_admin}` : '';

  switch (event.action) {
    case 'platform.support_session_started':
      return { title: `Acceso de soporte en solo lectura${who}`, detail: `Con la vista de ${after.as_user}; válido hasta el ${formatWhen(after.expires_at ? String(after.expires_at) : null)}`, tone: 'info' };
    case 'tenants.status_changed':
      return after.status === 'SUSPENDED'
        ? { title: `Academia suspendida${who}`, detail: after.reason ? `Motivo: ${after.reason}` : null, tone: 'warning' }
        : { title: `Academia reactivada${who}`, detail: null, tone: 'success' };
    case 'platform.tenant_updated': {
      const fields = Object.keys(after)
        .filter((key) => key in FIELD_LABEL)
        .map((key) => FIELD_LABEL[key as keyof TenantData]);
      return { title: `Datos corregidos${who}`, detail: fields.length > 0 ? `Cambió: ${fields.join(', ')}.` : null, tone: 'neutral' };
    }
    case 'platform.subscription_changed':
      return {
        title: `Plan cambiado${who}`,
        detail: `${after.status === 'TRIAL' ? 'Prueba gratuita' : (after.plan ?? 'Sin plan, sin límites')}${after.ends_at ? `, hasta el ${new Date(String(after.ends_at)).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' })}` : ''}${
          after.max_students ? `, hasta ${after.max_students} alumnos` : ''
        }.`,
        tone: 'info',
      };
    default:
      return { title: `Contraseña restablecida${who}`, detail: `Cuenta de ${after.user}. Deberá cambiarla al entrar.`, tone: 'neutral' };
  }
}

/* ───────────────────────────── Restablecer contraseña ───────────────────────────── */

const ResetPasswordModal: React.FC<{ tenantId: string; admin: TenantAdmin; onClose: () => void; onDone: () => Promise<void> }> = ({
  tenantId,
  admin,
  onClose,
  onDone,
}) => {
  const [password, setPassword] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await platformApi.resetAdminPassword(tenantId, admin.id, password);
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo restablecer la contraseña.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Restablecer contraseña"
      subtitle={`${admin.name} · ${admin.email}`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="reset-admin-form" processing={processing}>
            Restablecer
          </Button>
        </>
      }
    >
      <form id="reset-admin-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Alert tone="info">
          Se cerrarán sus sesiones abiertas y tendrá que cambiar esta contraseña al entrar. Queda registrado en la auditoría de la academia.
        </Alert>
        <Field label="Contraseña temporal" hint="Mínimo 8 caracteres. Entrégasela por un medio seguro.">
          <input className={INPUT_CLASS} required minLength={8} maxLength={100} autoComplete="off" autoFocus value={password} onChange={(event) => setPassword(event.target.value)} />
        </Field>
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Datos de la academia ───────────────────────────── */

const DataForm: React.FC<{ profile: Profile; onSaved: (profile: Profile) => void }> = ({ profile, onSaved }) => {
  const initial = (source: Profile): Record<keyof TenantData, string> => ({
    name: source.name,
    legal_name: source.legal_name ?? '',
    tax_id: source.tax_id ?? '',
    contact_email: source.contact_email ?? '',
    contact_phone: source.contact_phone ?? '',
    contact_address: source.contact_address ?? '',
  });
  const [form, setForm] = useState(() => initial(profile));
  const [processing, setProcessing] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const field = (key: keyof TenantData) => ({
    className: INPUT_CLASS,
    value: form[key],
    onChange: (event: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: event.target.value }),
  });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setNotice(null);
    try {
      const saved = await platformApi.update(profile.id, {
        name: form.name.trim(),
        legal_name: form.legal_name.trim() || null,
        tax_id: form.tax_id.trim() || null,
        contact_email: form.contact_email.trim() || null,
        contact_phone: form.contact_phone.trim() || null,
        contact_address: form.contact_address.trim() || null,
      });
      setForm(initial(saved));
      onSaved(saved);
      setNotice({ tone: 'success', text: 'Datos guardados. El cambio quedó en la auditoría de la academia.' });
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudieron guardar los datos.') });
    } finally {
      setProcessing(false);
    }
  };

  return (
    <Card>
      <CardHeader title="Datos de la academia" text="El subdominio no se cambia desde aquí: rompería los enlaces y los QR que la academia ya compartió." />
      <form onSubmit={submit} className="p-5 space-y-4">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="Nombre">
            <input {...field('name')} required maxLength={150} />
          </Field>
          <Field label="Razón social (opcional)">
            <input {...field('legal_name')} maxLength={200} />
          </Field>
          <Field label="RUC (opcional)">
            <input {...field('tax_id')} maxLength={30} />
          </Field>
          <Field label="Correo de contacto (opcional)">
            <input {...field('contact_email')} type="email" maxLength={180} />
          </Field>
          <Field label="Teléfono de contacto (opcional)">
            <input {...field('contact_phone')} maxLength={30} />
          </Field>
          <Field label="Dirección (opcional)">
            <input {...field('contact_address')} maxLength={250} />
          </Field>
        </div>
        <p className="text-xs text-slate-500">
          Moneda: {profile.currency} · Zona horaria: {profile.timezone}. Las define la academia en su Configuración.
        </p>
        <Button variant="primary" type="submit" processing={processing}>
          Guardar datos
        </Button>
      </form>
    </Card>
  );
};

/* ───────────────────────────── Ficha ───────────────────────────── */

type Dialog = { kind: 'suspend' | 'support' } | { kind: 'reset'; admin: TenantAdmin };

// Ficha de una academia: datos, cifras, administradores e historial de la plataforma. Sin datos de sus alumnos.
export const TenantProfile: React.FC<{ tenantId: string }> = ({ tenantId }) => {
  const { centralDomain } = useTenant();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [processing, setProcessing] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      setProfile(await platformApi.profile(tenantId));
      setError(null);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar la ficha de la academia.'));
    }
  }, [tenantId]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const back = (
    <Link to="/platform" className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4">
      <ArrowLeft className="w-4 h-4" /> Academias
    </Link>
  );

  if (error && !profile) {
    return (
      <>
        {back}
        <Card>
          <ErrorState message={error} onRetry={load} />
        </Card>
      </>
    );
  }
  if (!profile) return <LoadingState />;

  const active = profile.status === 'ACTIVE';
  const highest = Math.max(1, ...profile.monthly_sales.map((month) => Number(month.total)));

  const reactivate = async () => {
    setProcessing(true);
    setNotice(null);
    try {
      await platformApi.setStatus(profile.id, 'ACTIVE');
      await load();
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo reactivar la academia.') });
    } finally {
      setProcessing(false);
    }
  };

  return (
    <>
      {back}

      <Card className="p-5 mb-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-4 min-w-0">
            <Avatar name={profile.name} size="lg" />
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-[22px] leading-7 font-bold text-slate-900 tracking-tight">{profile.name}</h1>
                <Badge tone={active ? 'success' : 'warning'} dot>
                  {active ? 'Activa' : 'Suspendida'}
                </Badge>
              </div>
              <a
                href={tenantUrl(profile.slug, centralDomain, '/login')}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1 mt-1 text-[13px] text-brand-600 hover:text-brand-800"
              >
                {profile.slug}.{centralDomain}
                <ExternalLink className="w-3.5 h-3.5" />
              </a>
              <p className="text-xs text-slate-500 mt-1.5">
                Alta: {formatWhen(profile.created_at)} · Último acceso de alguien de la academia: {formatWhen(profile.last_login_at)}
              </p>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {active ? (
              <>
                <Button icon={<Eye className="w-4 h-4" />} onClick={() => setDialog({ kind: 'support' })}>
                  Entrar en modo soporte
                </Button>
                <Button variant="danger" onClick={() => setDialog({ kind: 'suspend' })}>
                  Suspender
                </Button>
              </>
            ) : (
              <Button variant="primary" processing={processing} onClick={reactivate}>
                Reactivar
              </Button>
            )}
          </div>
        </div>
      </Card>

      {notice && (
        <div className="mb-4">
          <Alert tone={notice.tone}>{notice.text}</Alert>
        </div>
      )}

      <SubscriptionCard tenantId={profile.id} subscription={profile.subscription} onChanged={load} />

      {/* Cifras: solo conteos, ningún dato de sus alumnos */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
        <StatCard label="Usuarios activos" value={profile.users_count} icon={Users} tone="brand" />
        <StatCard label="Alumnos" value={profile.students_count} hint="con matrícula activa" icon={GraduationCap} tone="info" />
        <StatCard label="Cursos publicados" value={profile.published_courses_count} icon={BookOpen} tone="violet" />
        <StatCard label="Ventas del mes" value={formatMoney(profile.sales_this_month, profile.currency)} hint="órdenes pagadas" icon={Receipt} tone="success" />
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
        <StatCard label="Matrículas nuevas" value={profile.enrollments_this_month} hint="este mes" icon={ClipboardList} tone="info" />
        <StatCard label="Certificados vigentes" value={profile.certificates_issued} hint="emitidos en total" icon={Award} tone="warning" />
        <StatCard label="Espacio en archivos" value={profile.storage_bytes === 0 ? '0 KB' : formatSize(profile.storage_bytes)} icon={HardDrive} tone="neutral" />
      </div>

      <div className="space-y-5">
        <Card>
          <CardHeader title="Ventas de los últimos seis meses" text={`Órdenes pagadas, en ${profile.currency}, como en los reportes de la propia academia.`} />
          <ol className="grid grid-cols-6 gap-2 sm:gap-4 items-end px-5 pt-5 pb-4" aria-label="Ventas por mes">
            {profile.monthly_sales.map((month) => (
              <li key={month.month} className="flex flex-col items-center gap-1.5 min-w-0">
                <span className="text-[11px] sm:text-xs font-semibold text-slate-800 tnum truncate max-w-full">{formatMoney(month.total, profile.currency)}</span>
                <span className="w-full h-24 flex items-end rounded-lg bg-slate-100 overflow-hidden" aria-hidden="true">
                  <span className="block w-full rounded-lg bg-gradient-to-t from-brand-700 to-sky-500" style={{ height: `${(Number(month.total) / highest) * 100}%` }} />
                </span>
                <span className="text-xs text-slate-500">{formatMonth(month.month)}</span>
                <span className="text-[11px] text-slate-500">
                  {month.orders} {month.orders === 1 ? 'orden' : 'órdenes'}
                </span>
              </li>
            ))}
          </ol>
        </Card>

        <DataForm profile={profile} onSaved={setProfile} />

        <Card>
          <CardHeader title="Administradores" text="A quién escribirle en esta academia. Si alguno perdió su acceso, puedes darle una contraseña temporal." />
          {profile.admins.length === 0 ? (
            <EmptyState icon={Users} title="Esta academia no tiene administradores" />
          ) : (
            <Table>
              <THead>
                <Th>Administrador</Th>
                <Th>Último acceso</Th>
                <Th>Cuenta</Th>
                <Th />
              </THead>
              <TBody>
                {profile.admins.map((admin) => (
                  <Tr key={admin.id}>
                    <Td>
                      <div className="flex items-center gap-3">
                        <Avatar name={admin.name} size="sm" />
                        <div className="min-w-0">
                          <p className="font-semibold text-slate-900 whitespace-nowrap">{admin.name}</p>
                          <p className="text-xs text-slate-500">{admin.email}</p>
                        </div>
                      </div>
                    </Td>
                    <Td className="whitespace-nowrap text-slate-600 tnum">{formatWhen(admin.last_login_at)}</Td>
                    <Td className="whitespace-nowrap">
                      <Badge tone={admin.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                        {admin.status === 'ACTIVE' ? 'Activa' : 'Inactiva'}
                      </Badge>
                      {admin.must_change_password && <span className="ml-2 text-xs text-amber-700">contraseña temporal</span>}
                    </Td>
                    <Td className="text-right">
                      <Button size="sm" icon={<KeyRound className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'reset', admin })}>
                        Restablecer contraseña
                      </Button>
                    </Td>
                  </Tr>
                ))}
              </TBody>
            </Table>
          )}
        </Card>

        <Card>
          <CardHeader title="Historial de la plataforma" text="Accesos de soporte, suspensiones y cambios hechos desde esta consola. La academia también los ve en su auditoría." />
          {profile.history.length === 0 ? (
            <EmptyState icon={ClipboardList} title="Sin movimientos" text="Aún no se entró en modo soporte ni se cambió nada desde la consola." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {profile.history.map((event) => {
                const { title, detail, tone } = describe(event);
                return (
                  <li key={event.id} className="flex flex-wrap items-start gap-3 px-5 py-3">
                    <Badge tone={tone} dot>
                      {formatWhen(event.created_at)}
                    </Badge>
                    <div className="min-w-0 flex-1">
                      <p className="text-[13px] font-semibold text-slate-900 break-words">{title}</p>
                      {detail && <p className="text-xs text-slate-500 break-words">{detail}</p>}
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>
      </div>

      {dialog?.kind === 'suspend' && (
        <SuspendTenantModal
          tenant={profile}
          onClose={() => setDialog(null)}
          onDone={async () => {
            await load();
            setDialog(null);
          }}
        />
      )}
      {dialog?.kind === 'support' && (
        <ConfirmModal
          title="Entrar en modo soporte"
          text={`Se abrirá «${profile.name}» en otra pestaña con lo que ve su administrador, en solo lectura y durante 30 minutos. No podrás cambiar nada, y el acceso queda registrado en la auditoría de la academia, a la vista de su administrador.`}
          confirmLabel="Entrar en solo lectura"
          variant="primary"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            const asUser = await platformApi.openSupport(profile, centralDomain);
            setNotice({ tone: 'success', text: `Se abrió la academia en otra pestaña, en solo lectura, con la vista de ${asUser}. El acceso dura 30 minutos.` });
            setDialog(null);
            await load();
          }}
        />
      )}
      {dialog?.kind === 'reset' && (
        <ResetPasswordModal
          tenantId={profile.id}
          admin={dialog.admin}
          onClose={() => setDialog(null)}
          onDone={async () => {
            setNotice({ tone: 'success', text: `Contraseña restablecida para ${dialog.admin.email}. Entrégasela por un medio seguro.` });
            setDialog(null);
            await load();
          }}
        />
      )}
    </>
  );
};
