import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Award, CheckCircle2, KeyRound, Receipt, ShieldCheck, Undo2, UserPlus, Users } from 'lucide-react';
import { SelectAll } from '../../../shared/ui/filters';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  CardHeader,
  CHECKBOX_CLASS,
  ConfirmModal,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  StatCard,
} from '../../../shared/ui';
import { formatDay } from '../../crm/api';
import { OrderStatusBadge } from '../../sales/components/OrderParts';
import { formatMoney, orderCode } from '../../sales/api';
import { accountApi, type Account, type Assignment, type CoursePool, type Member } from '../api';

type Api = ReturnType<typeof accountApi>;

const ProgressBar: React.FC<{ value: number }> = ({ value }) => (
  <div className="flex items-center gap-2 min-w-[120px]">
    <div className="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuenow={value} aria-valuemin={0} aria-valuemax={100}>
      <div className={`h-full rounded-full ${value === 100 ? 'bg-emerald-500' : 'bg-gradient-to-r from-brand-600 to-sky-500'}`} style={{ width: `${value}%` }} />
    </div>
    <span className="text-xs font-semibold text-slate-700 tnum w-9 text-right">{value}%</span>
  </div>
);

// Elegir a qué trabajadores se les asigna una licencia del curso
const AssignModal: React.FC<{
  pool: CoursePool;
  members: Member[];
  api: Api;
  onClose: () => void;
  onDone: (account: Account) => void;
}> = ({ pool, members, api, onClose, onDone }) => {
  const [selected, setSelected] = useState<string[]>([]);
  const [search, setSearch] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const holders = new Set(pool.assignments.map((assignment) => assignment.student.id));
  // Los responsables no cursan: solo se asigna a trabajadores que aún no tienen el curso
  const candidates = members.filter((member) => !member.is_manager && member.active && !holders.has(member.id));
  const shown = candidates.filter((member) => `${member.name} ${member.email}`.toLowerCase().includes(search.trim().toLowerCase()));
  const left = pool.available - selected.length;

  const toggle = (id: string) => setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));

  const submit = async () => {
    setProcessing(true);
    setError(null);
    try {
      onDone(await api.assign(pool.course.id, selected));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudieron asignar las licencias.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Asignar licencias"
      subtitle={pool.course.title}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" processing={processing} disabled={selected.length === 0 || left < 0} onClick={submit}>
            Asignar {selected.length > 0 ? selected.length : ''}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Alert tone={left < 0 ? 'danger' : 'info'}>
          {left < 0
            ? `Elegiste ${selected.length} personas y solo hay ${pool.available} licencias disponibles.`
            : `Quedan ${left} de ${pool.available} licencias disponibles. Cada persona elegida queda matriculada de inmediato${
                pool.course.access_days ? `, con ${pool.course.access_days} días de acceso` : ''
              }.`}
        </Alert>
        {candidates.length > 6 && (
          <input className={INPUT_CLASS} placeholder="Buscar trabajador" aria-label="Buscar trabajador" value={search} onChange={(event) => setSearch(event.target.value)} />
        )}
        {candidates.length === 0 ? (
          <p className="text-[13px] text-slate-500">No hay trabajadores sin este curso. Registra primero a quienes lo llevarán.</p>
        ) : (
          <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 max-h-72 overflow-y-auto">
            <li className="px-3.5 py-2 bg-slate-50/80 rounded-t-xl">
              <SelectAll ids={shown.map((member) => member.id)} selected={selected} onChange={setSelected}>
                Seleccionar todos ({shown.length})
              </SelectAll>
            </li>
            {shown.map((member) => (
              <li key={member.id}>
                <label className="flex items-center gap-3 px-3.5 py-2.5 cursor-pointer hover:bg-brand-50/40">
                  <input type="checkbox" className={CHECKBOX_CLASS} checked={selected.includes(member.id)} onChange={() => toggle(member.id)} />
                  <span className="min-w-0">
                    <span className="block text-[13px] font-semibold text-slate-900 truncate">{member.name}</span>
                    <span className="block text-xs text-slate-500 truncate">{member.email}</span>
                  </span>
                </label>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  );
};

const MemberModal: React.FC<{
  staff: boolean;
  api: Api;
  onClose: () => void;
  onDone: (account: Account) => void;
}> = ({ staff, api, onClose, onDone }) => {
  const [form, setForm] = useState({ first_name: '', last_name: '', email: '', password: '' });
  const [manager, setManager] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const bind = (key: keyof typeof form) => ({
    value: form[key],
    onChange: (event: React.ChangeEvent<HTMLInputElement>) => setForm((current) => ({ ...current, [key]: event.target.value })),
  });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      onDone(
        await api.addMember({
          first_name: form.first_name.trim(),
          last_name: form.last_name.trim(),
          email: form.email.trim(),
          password: form.password || null,
          ...(staff ? { is_manager: manager } : {}),
        }),
      );
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo registrar a la persona.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={staff ? 'Agregar persona' : 'Registrar trabajador'}
      subtitle="Tendrá su propia cuenta para entrar a la academia."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="member-form" processing={processing}>
            Registrar
          </Button>
        </>
      }
    >
      <form id="member-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Nombres">
            <input className={INPUT_CLASS} required maxLength={100} autoFocus {...bind('first_name')} />
          </Field>
          <Field label="Apellidos">
            <input className={INPUT_CLASS} required maxLength={100} {...bind('last_name')} />
          </Field>
        </div>
        <Field label="Correo" hint="Con este correo iniciará sesión.">
          <input className={INPUT_CLASS} type="email" required maxLength={150} {...bind('email')} />
        </Field>
        <Field
          label="Contraseña inicial"
          hint={
            staff
              ? 'Mínimo 8 caracteres. Déjala vacía solo si el correo ya tiene una cuenta en la academia: se vinculará a esta empresa.'
              : 'Mínimo 8 caracteres. Entrégasela a la persona; podrá cambiarla desde su cuenta.'
          }
        >
          <input className={INPUT_CLASS} type="text" autoComplete="off" minLength={8} required={!staff} {...bind('password')} />
        </Field>
        {staff && (
          <label className="flex items-start gap-2.5 text-[13px] text-slate-700 cursor-pointer">
            <input type="checkbox" className={`${CHECKBOX_CLASS} mt-0.5`} checked={manager} onChange={(event) => setManager(event.target.checked)} />
            <span>
              Es responsable de la empresa
              <span className="block text-xs text-slate-500">Entra al portal de empresa: registra trabajadores, asigna licencias y responde cotizaciones.</span>
            </span>
          </label>
        )}
      </form>
    </Modal>
  );
};

type Dialog = { kind: 'assign'; pool: CoursePool } | { kind: 'member' } | { kind: 'revoke'; assignment: Assignment; course: string };

// Cuenta de una empresa cliente: licencias por curso, personas y compras.
// La usan el responsable en su portal y el personal de la academia.
export const AccountView: React.FC<{
  base: string;
  staff: boolean;
  canAssign: boolean;
  canAddMembers: boolean;
  onLoaded?: (account: Account) => void;
}> = ({ base, staff, canAssign, canAddMembers, onLoaded }) => {
  const api = useMemo(() => accountApi(base), [base]);
  const [account, setAccount] = useState<Account | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);

  const apply = useCallback(
    (loaded: Account) => {
      setAccount(loaded);
      setError(null);
      onLoaded?.(loaded);
    },
    [onLoaded],
  );

  const load = useCallback(() => {
    api
      .get()
      .then(apply)
      .catch((err) => setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.')));
  }, [api, apply]);

  useEffect(load, [load]);

  const done = (loaded: Account) => {
    apply(loaded);
    setDialog(null);
  };

  if (error && !account) {
    return (
      <Card>
        <ErrorState message={error} onRetry={load} />
      </Card>
    );
  }
  if (!account) {
    return (
      <Card>
        <LoadingState />
      </Card>
    );
  }

  const totals = account.courses.reduce(
    (sum, pool) => ({ total: sum.total + pool.total, assigned: sum.assigned + pool.assigned, available: sum.available + pool.available }),
    { total: 0, assigned: 0, available: 0 },
  );
  const completed = account.courses.reduce((sum, pool) => sum + pool.assignments.filter((assignment) => assignment.completed_at).length, 0);
  const workers = account.members.filter((member) => !member.is_manager);

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Licencias compradas" value={totals.total} icon={KeyRound} tone="brand" />
        <StatCard label="Disponibles" value={totals.available} hint="Por asignar" icon={ShieldCheck} tone="info" />
        <StatCard label="Asignadas" value={totals.assigned} icon={Users} tone="violet" />
        <StatCard label="Cursos completados" value={completed} icon={Award} tone="success" />
      </div>

      <Card>
        <CardHeader title="Licencias por curso" text="Cada licencia es un cupo de un curso para un trabajador." />
        {account.courses.length === 0 ? (
          <EmptyState
            icon={KeyRound}
            title="Aún no hay licencias"
            text={
              staff
                ? 'Las licencias se entregan cuando se confirma el pago de una venta a esta empresa, o al adelantarlas desde Ventas y pagos.'
                : 'Aparecerán aquí cuando la academia confirme el pago de tu compra.'
            }
          />
        ) : (
          <div className="divide-y divide-slate-200/80">
            {account.courses.map((pool) => (
              <section key={pool.course.id} className="px-5 py-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="min-w-0">
                    <h3 className="text-sm font-semibold text-slate-900">{pool.course.title}</h3>
                    <p className="text-xs text-slate-500 mt-0.5">
                      {pool.assigned} de {pool.total} asignadas · <strong className="text-slate-700">{pool.available} disponibles</strong>
                      {pool.available > 0 && pool.assign_before && ` · asígnalas antes del ${formatDay(pool.assign_before)}`}
                      {pool.expired > 0 && ` · ${pool.expired} vencidas sin asignar`}
                    </p>
                  </div>
                  {canAssign && (
                    <Button size="sm" variant="primary" icon={<UserPlus className="w-3.5 h-3.5" />} disabled={pool.available === 0} onClick={() => setDialog({ kind: 'assign', pool })}>
                      Asignar
                    </Button>
                  )}
                </div>

                {pool.assignments.length > 0 && (
                  <div className="mt-3 rounded-xl border border-slate-200 overflow-x-auto">
                    <table className="w-full text-[13px]">
                      <thead>
                        <tr className="bg-slate-50/80 border-b border-slate-200/80 text-xs text-slate-500 text-left">
                          <th scope="col" className="px-3.5 py-2.5 font-semibold">
                            Trabajador
                          </th>
                          <th scope="col" className="px-3.5 py-2.5 font-semibold">
                            Avance
                          </th>
                          <th scope="col" className="px-3.5 py-2.5 font-semibold">
                            Situación
                          </th>
                          <th scope="col" className="px-3.5 py-2.5" />
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {pool.assignments.map((assignment) => (
                          <tr key={assignment.id}>
                            <td className="px-3.5 py-2.5">
                              <p className="font-semibold text-slate-900 whitespace-nowrap">{assignment.student.name}</p>
                              <p className="text-xs text-slate-500">{assignment.student.email}</p>
                            </td>
                            <td className="px-3.5 py-2.5">
                              <ProgressBar value={assignment.progress} />
                            </td>
                            <td className="px-3.5 py-2.5 whitespace-nowrap">
                              {assignment.completed_at ? (
                                <Badge tone="success" dot>
                                  Completado
                                </Badge>
                              ) : assignment.enrollment_status === 'EXPIRED' ? (
                                <Badge tone="warning" dot>
                                  Acceso vencido
                                </Badge>
                              ) : assignment.progress > 0 ? (
                                <Badge tone="info" dot>
                                  En curso
                                </Badge>
                              ) : (
                                <Badge dot>Sin empezar</Badge>
                              )}
                              <p className="text-xs text-slate-500 mt-1">
                                {assignment.certificate_code
                                  ? `Certificado ${assignment.certificate_code}`
                                  : assignment.access_until
                                    ? `Acceso hasta el ${formatDay(assignment.access_until)}`
                                    : `Asignada el ${formatDay(assignment.assigned_at)}`}
                              </p>
                            </td>
                            <td className="px-3.5 py-2.5 text-right">
                              {canAssign && assignment.can_revoke && (
                                <Button
                                  size="sm"
                                  icon={<Undo2 className="w-3.5 h-3.5" />}
                                  aria-label={`Retirar la licencia de ${assignment.student.name}`}
                                  onClick={() => setDialog({ kind: 'revoke', assignment, course: pool.course.title })}
                                >
                                  Retirar
                                </Button>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </section>
            ))}
          </div>
        )}
      </Card>

      <div className="grid lg:grid-cols-2 gap-6 items-start">
        <Card>
          <CardHeader
            title={`Personas (${account.members.length})`}
            text={`${workers.length} ${workers.length === 1 ? 'trabajador' : 'trabajadores'} registrados.`}
            action={
              canAddMembers && (
                <Button size="sm" icon={<UserPlus className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'member' })}>
                  {staff ? 'Agregar' : 'Registrar trabajador'}
                </Button>
              )
            }
          />
          {account.members.length === 0 ? (
            <EmptyState
              icon={Users}
              title="Aún no hay personas"
              text={staff ? 'Agrega al responsable de la empresa para darle acceso a su portal, o registra a sus trabajadores.' : 'Registra a los trabajadores que llevarán los cursos.'}
            />
          ) : (
            <ul className="divide-y divide-slate-100">
              {account.members.map((member) => (
                <li key={member.id} className="flex items-center gap-3 px-5 py-3">
                  <Avatar name={member.name} size="sm" />
                  <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-semibold text-slate-900 truncate">{member.name}</p>
                    <p className="text-xs text-slate-500 truncate">{member.email}</p>
                  </div>
                  {member.is_manager ? (
                    <Badge tone="brand">Responsable</Badge>
                  ) : (
                    <span className="text-xs text-slate-500 whitespace-nowrap tnum">
                      {member.licenses} {member.licenses === 1 ? 'curso' : 'cursos'}
                    </span>
                  )}
                  {!member.active && <Badge tone="warning">Inactivo</Badge>}
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <CardHeader title="Compras" text={staff ? 'Ventas de la academia a esta empresa.' : 'Lo que tu empresa compró a la academia y el estado de cada pago.'} />
          {account.orders.length === 0 ? (
            <EmptyState icon={Receipt} title="Aún no hay compras" text="Una compra nace cuando se acepta una cotización." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {account.orders.map((order) => (
                <li key={order.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                  <div>
                    <p className="text-[13px] font-semibold text-slate-900 tnum">Orden {orderCode(order.number)}</p>
                    <p className="text-xs text-slate-500">
                      {formatDay(order.created_at)}
                      {order.status === 'PENDING' && order.licenses_released_at && ' · licencias entregadas, pago pendiente'}
                    </p>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="text-[13px] font-semibold text-slate-900 tnum">{formatMoney(order.total_amount, order.currency)}</span>
                    <OrderStatusBadge status={order.status} />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {dialog?.kind === 'assign' && <AssignModal pool={dialog.pool} members={account.members} api={api} onClose={() => setDialog(null)} onDone={done} />}
      {dialog?.kind === 'member' && <MemberModal staff={staff} api={api} onClose={() => setDialog(null)} onDone={done} />}
      {dialog?.kind === 'revoke' && (
        <ConfirmModal
          title="Retirar licencia"
          text={`${dialog.assignment.student.name} perderá el acceso a "${dialog.course}" y la licencia volverá a quedar disponible para otra persona. Solo se puede hacer mientras no haya empezado el curso.`}
          confirmLabel="Retirar licencia"
          onClose={() => setDialog(null)}
          onConfirm={async () => done(await api.revoke(dialog.assignment.id))}
        />
      )}
      {account.courses.some((pool) => pool.assignments.some((assignment) => assignment.completed_at)) && (
        <p className="flex items-center gap-1.5 text-xs text-slate-500">
          <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />
          Los certificados se verifican con su código en la página pública de la academia (/verify).
        </p>
      )}
    </div>
  );
};
