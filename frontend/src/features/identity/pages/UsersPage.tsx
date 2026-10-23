import React, { useCallback, useEffect, useState } from 'react';
import { FileSpreadsheet, KeyRound, LogOut, MailPlus, Pencil, Plus, Search, ShieldOff, UserCheck, UserCog, UserX, Users, UsersRound } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  ConfirmModal,
  CHECKBOX_CLASS,
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
import { BulkBar, FilterCards, HelpTip, SelectAll, SelectRow, type FilterCard } from '../../../shared/ui/filters';
import { ImportModal } from '../../../shared/ui/ImportModal';
import { GroupsTab } from '../components/GroupsTab';
import { ResetPasswordModal } from '../components/ResetPasswordModal';
import { DOCUMENT_TYPES, identityApi, type DocumentType, type PageMeta, type Role, type UserRow } from '../api';
import { AccessChoice, type AccessMode } from '../components/AccessChoice';
import { SearchSelect } from '../../../shared/ui/SearchSelect';

const STATUS_LABEL: Record<UserRow['status'], string> = { ACTIVE: 'Activo', INACTIVE: 'Inactivo' };

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' }) : 'Nunca';
}

interface FormState {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  doc_type: DocumentType | '';
  doc_number: string;
  password: string;
  access: AccessMode;
  status: UserRow['status'];
  roles: string[];
}

const EMPTY_FORM: FormState = {
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  doc_type: '',
  doc_number: '',
  password: '',
  access: 'invite',
  status: 'ACTIVE',
  roles: [],
};

interface UserFormProps {
  user: UserRow | null;
  roles: Role[];
  onClose: () => void;
  onSaved: () => void;
}

const UserForm: React.FC<UserFormProps> = ({ user, roles, onClose, onSaved }) => {
  const [form, setForm] = useState<FormState>(
    user
      ? {
          ...EMPTY_FORM,
          ...user,
          phone: user.phone ?? '',
          doc_type: user.doc_type ?? '',
          doc_number: user.doc_number ?? '',
          roles: user.role_ids,
        }
      : EMPTY_FORM,
  );
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const set = (key: keyof FormState) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((prev) => ({ ...prev, [key]: event.target.value }));

  const toggleRole = (id: string) =>
    setForm((prev) => ({
      ...prev,
      roles: prev.roles.includes(id) ? prev.roles.filter((r) => r !== id) : [...prev.roles, id],
    }));

  const [sessionsNotice, setSessionsNotice] = useState<string | null>(null);
  const [twoFactorOn, setTwoFactorOn] = useState(Boolean(user?.two_factor_enabled));

  const resetTwoFactor = async () => {
    if (!user) return;
    setProcessing(true);
    setError(null);
    setSessionsNotice(null);

    try {
      await identityApi.resetTwoFactor(user.id);
      setTwoFactorOn(false);
      setSessionsNotice('Se quitó su verificación en dos pasos y se cerraron sus sesiones. Entrará solo con su contraseña y podrá volver a activarla.');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo quitar la verificación en dos pasos.'));
    } finally {
      setProcessing(false);
    }
  };
  const [resetting, setResetting] = useState(false);

  const [invitedAt, setInvitedAt] = useState<string | null>(null);
  const resendInvitation = async () => {
    if (!user) return;
    setProcessing(true);
    setError(null);
    setSessionsNotice(null);
    try {
      const sent = await identityApi.resendInvitation(user.id);
      setInvitedAt(sent.invited_at);
      setSessionsNotice(`Invitación reenviada a ${sent.email}. El enlace anterior ya no sirve.`);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo reenviar la invitación.'));
    } finally {
      setProcessing(false);
    }
  };

  const revokeSessions = async () => {
    if (!user) return;
    setProcessing(true);
    setError(null);
    setSessionsNotice(null);

    try {
      const revoked = await identityApi.revokeUserSessions(user.id);
      setSessionsNotice(
        revoked === 0
          ? 'No tenía otras sesiones abiertas.'
          : `Se ${revoked === 1 ? 'cerró 1 sesión' : `cerraron ${revoked} sesiones`}. Tendrá que volver a iniciar sesión.`,
      );
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudieron cerrar las sesiones.'));
    } finally {
      setProcessing(false);
    }
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (form.roles.length === 0) {
      setError('Selecciona al menos un rol.');
      return;
    }

    setProcessing(true);
    setError(null);

    const base = {
      first_name: form.first_name,
      last_name: form.last_name,
      phone: form.phone || null,
      doc_type: form.doc_type || null,
      doc_number: form.doc_type ? form.doc_number : null,
    };

    try {
      if (user) {
        await identityApi.updateUser(user.id, { ...base, status: form.status });
        const rolesChanged = [...form.roles].sort().join() !== [...user.role_ids].sort().join();
        if (rolesChanged) await identityApi.setUserRoles(user.id, form.roles);
      } else {
        await identityApi.createUser({ ...base, email: form.email, password: form.access === 'password' ? form.password : null, roles: form.roles });
      }
      onSaved();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el usuario.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={user ? 'Editar usuario' : 'Nuevo usuario'}
      subtitle={user ? user.email : 'Tendrá acceso a tu organización con los roles que elijas.'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose} disabled={processing}>
            Cancelar
          </Button>
          <Button variant="primary" type="submit" form="user-form" processing={processing}>
            {user ? 'Guardar cambios' : 'Crear usuario'}
          </Button>
        </>
      }
    >
      <form id="user-form" onSubmit={handleSubmit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Nombres">
            <input className={INPUT_CLASS} required maxLength={100} value={form.first_name} onChange={set('first_name')} />
          </Field>
          <Field label="Apellidos">
            <input className={INPUT_CLASS} required maxLength={100} value={form.last_name} onChange={set('last_name')} />
          </Field>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Tipo de documento (opcional)">
            <select className={INPUT_CLASS} value={form.doc_type} onChange={set('doc_type')}>
              <option value="">Sin documento</option>
              {Object.entries(DOCUMENT_TYPES).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Número de documento">
            <input
              className={INPUT_CLASS}
              required={form.doc_type !== ''}
              disabled={form.doc_type === ''}
              inputMode={form.doc_type === 'DNI' ? 'numeric' : undefined}
              maxLength={form.doc_type === 'DNI' ? 8 : 12}
              placeholder={form.doc_type === 'DNI' ? '8 dígitos' : undefined}
              value={form.doc_type === '' ? '' : form.doc_number}
              onChange={set('doc_number')}
            />
          </Field>
        </div>

        <Field label="Correo electrónico" hint={user ? 'El correo de acceso no se puede cambiar.' : undefined}>
          <input
            className={INPUT_CLASS}
            type="email"
            required
            disabled={!!user}
            value={form.email}
            onChange={set('email')}
          />
        </Field>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Teléfono (opcional)">
            <input className={INPUT_CLASS} maxLength={30} value={form.phone} onChange={set('phone')} />
          </Field>
          {user ? (
            <Field label="Estado">
              <select className={INPUT_CLASS} value={form.status} onChange={set('status')}>
                <option value="ACTIVE">Activo</option>
                <option value="INACTIVE">Inactivo</option>
              </select>
            </Field>
          ) : null}
        </div>
        {!user && (
          <AccessChoice
            mode={form.access}
            password={form.password}
            passwordHint="Mínimo 8 caracteres."
            onChange={(change) => setForm((previous) => ({ ...previous, ...(change.mode ? { access: change.mode } : {}), ...(change.password !== undefined ? { password: change.password } : {}) }))}
          />
        )}

        <fieldset>
          <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Roles</legend>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
            {roles.map((role) => {
              const checked = form.roles.includes(role.id);
              return (
                <label
                  key={role.id}
                  className={`flex items-center gap-2.5 px-3 py-2.5 border rounded-xl text-[13px] cursor-pointer transition-colors ${
                    checked
                      ? 'border-brand-300 bg-brand-50 text-brand-800'
                      : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <input type="checkbox" className={CHECKBOX_CLASS} checked={checked} onChange={() => toggleRole(role.id)} />
                  <span className="truncate font-medium">{role.name}</span>
                </label>
              );
            })}
          </div>
        </fieldset>

        {user?.invited_at && (
          <div className="flex flex-wrap items-center justify-between gap-3 px-3.5 py-3 border border-amber-200 bg-amber-50/60 rounded-xl">
            <div className="min-w-0">
              <p className="text-[13px] font-medium text-slate-700">Invitación pendiente</p>
              <p className="text-xs text-slate-500">Se la enviamos el {formatDate(invitedAt ?? user.invited_at)} y todavía no crea su contraseña. Reenvíala si venció o no le llegó.</p>
            </div>
            <Button size="sm" icon={<MailPlus className="w-4 h-4" />} onClick={resendInvitation} disabled={processing}>
              Reenviar invitación
            </Button>
          </div>
        )}
        {user && (
          <div className="flex flex-wrap items-center justify-between gap-3 px-3.5 py-3 border border-slate-200 rounded-xl">
            <div className="min-w-0">
              <p className="text-[13px] font-medium text-slate-700">Sesiones abiertas</p>
              <p className="text-xs text-slate-500">Útil si perdió un equipo o sospechas que alguien más usa su cuenta.</p>
            </div>
            <Button size="sm" icon={<LogOut className="w-4 h-4" />} onClick={revokeSessions} disabled={processing}>
              Cerrar sesiones
            </Button>
          </div>
        )}
        {user && (
          <div className="flex flex-wrap items-center justify-between gap-3 px-3.5 py-3 border border-slate-200 rounded-xl">
            <div className="min-w-0">
              <p className="text-[13px] font-medium text-slate-700">Contraseña</p>
              <p className="text-xs text-slate-500">Si la olvidó o alguien más la cambió: le das una temporal y al entrar elige una propia.</p>
            </div>
            <Button size="sm" icon={<KeyRound className="w-4 h-4" />} onClick={() => setResetting(true)} disabled={processing}>
              Restablecer contraseña
            </Button>
          </div>
        )}
        {user && twoFactorOn && (
          <div className="flex flex-wrap items-center justify-between gap-3 px-3.5 py-3 border border-slate-200 rounded-xl">
            <div className="min-w-0">
              <p className="text-[13px] font-medium text-slate-700">Verificación en dos pasos: activa</p>
              <p className="text-xs text-slate-500">Si perdió su teléfono y sus códigos de respaldo, quítasela para que pueda entrar.</p>
            </div>
            <Button size="sm" variant="danger" icon={<ShieldOff className="w-4 h-4" />} onClick={resetTwoFactor} disabled={processing}>
              Quitar
            </Button>
          </div>
        )}
        {sessionsNotice && <Alert tone="success">{sessionsNotice}</Alert>}
      </form>
      {resetting && user && (
        <ResetPasswordModal user={{ id: user.id, name: `${user.first_name} ${user.last_name}`, email: user.email }} onClose={() => setResetting(false)} />
      )}
    </Modal>
  );
};

export const UsersPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canCreate = hasPermission('users.create');
  const canUpdate = hasPermission('users.update');
  // Elegir roles exige poder consultarlos
  const canPickRoles = hasPermission('roles.read');

  const [rows, setRows] = useState<UserRow[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [roles, setRoles] = useState<Role[]>([]);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [editing, setEditing] = useState<UserRow | 'new' | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [bulk, setBulk] = useState<UserRow['status'] | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'info'; text: string } | null>(null);
  const [tab, setTab] = useState<'users' | 'groups'>('users');
  const [importing, setImporting] = useState(false);

  const load = useCallback(async () => {
    setState('loading');
    try {
      const result = await identityApi.listUsers({ search: search || undefined, status: status || undefined, page });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, page]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    if (!canPickRoles) return;
    identityApi.listRoles().then(setRoles).catch(() => setRoles([]));
  }, [canPickRoles]);

  const filtered = search !== '' || status !== '';
  const filter = (apply: () => void) => {
    apply();
    setPage(1);
    setSelected([]);
  };

  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todos', value: counts.ALL ?? null, icon: Users, tone: 'brand', help: 'Todas las personas con cuenta en la academia que coinciden con la búsqueda.' },
    { key: 'ACTIVE', label: 'Activos', value: counts.ACTIVE ?? null, icon: UserCheck, tone: 'success', help: 'Pueden iniciar sesión y usar el sistema según sus roles.' },
    { key: 'INACTIVE', label: 'Inactivos', value: counts.INACTIVE ?? null, icon: UserX, tone: 'neutral', help: 'Cuenta desactivada: no puede iniciar sesión. Conserva su historial y se puede reactivar.' },
  ];

  const applyBulk = async (target: UserRow['status']) => {
    const result = await identityApi.setUsersStatus(selected, target);
    const verb = target === 'ACTIVE' ? 'activaron' : 'desactivaron';
    const skipped = result.skipped.map((item) => `${item.email}: ${item.reason}`).join(' ');
    setNotice({
      tone: result.skipped.length > 0 ? 'info' : 'success',
      text: `Se ${verb} ${result.updated} ${result.updated === 1 ? 'cuenta' : 'cuentas'}.${skipped ? ` No cambiaron: ${skipped}` : ''}`,
    });
    setSelected([]);
    setBulk(null);
    await load();
  };

  return (
    <div>
      <PageHeader
        title="Usuarios"
        icon={UserCog}
        subtitle="Personas con acceso a tu organización y los roles que tienen asignados."
        action={
          canCreate && (
            <>
              <Button icon={<FileSpreadsheet className="w-4 h-4" />} onClick={() => setImporting(true)}>
                Importar
              </Button>
              {canPickRoles && (
                <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setEditing('new')}>
                  Nuevo usuario
                </Button>
              )}
            </>
          )
        }
      />

      <div role="tablist" aria-label="Secciones de usuarios" className="inline-flex gap-1 p-1 mb-5 rounded-xl bg-slate-100 border border-slate-200/70 max-w-full overflow-x-auto">
        {(
          [
            { id: 'users', label: 'Usuarios', icon: Users },
            { id: 'groups', label: 'Grupos', icon: UsersRound },
          ] as const
        ).map(({ id, label, icon: Icon }) => (
          <button
            key={id}
            type="button"
            role="tab"
            aria-selected={tab === id}
            onClick={() => setTab(id)}
            className={`inline-flex items-center gap-2 h-9 px-3.5 rounded-lg text-[13px] font-semibold whitespace-nowrap cursor-pointer transition-all ${
              tab === id ? 'bg-white text-brand-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            <Icon className="w-4 h-4" />
            {label}
          </button>
        ))}
      </div>

      {tab === 'groups' && <GroupsTab canManage={canUpdate} />}

      {tab === 'users' && (
        <>

      <FilterCards cards={cards} active={status} label="Usuarios por estado" onSelect={(key) => filter(() => setStatus(key))} />

      {notice && (
        <div className="mb-4">
          <Alert tone={notice.tone}>{notice.text}</Alert>
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por nombre o correo"
              aria-label="Buscar usuarios"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <div className="flex items-center gap-1.5">
            <SearchSelect
              className={`${INPUT_CLASS} !w-48`}
              aria-label="Filtrar por estado"
              value={status}
              onChange={(event) => filter(() => setStatus(event.target.value))}
            >
              <option value="">Todos los estados</option>
              <option value="ACTIVE">Activos</option>
              <option value="INACTIVE">Inactivos</option>
            </SearchSelect>
            <HelpTip
              text="Activo: puede iniciar sesión. Inactivo: su cuenta está desactivada y no puede entrar, pero conserva su historial."
              label="Qué significa el estado de un usuario"
            />
          </div>
        </div>

        {canUpdate && (
          <BulkBar count={selected.length} noun={['usuario elegido', 'usuarios elegidos']} onClear={() => setSelected([])}>
            <Button size="sm" icon={<UserCheck className="w-3.5 h-3.5" />} onClick={() => setBulk('ACTIVE')}>
              Activar
            </Button>
            <Button size="sm" variant="danger" icon={<UserX className="w-3.5 h-3.5" />} onClick={() => setBulk('INACTIVE')}>
              Desactivar
            </Button>
          </BulkBar>
        )}

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={Users}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay usuarios'}
            text={filtered ? 'Prueba con otra búsqueda o cambia el filtro de estado.' : undefined}
            action={
              filtered && (
                <Button
                  onClick={() =>
                    filter(() => {
                      setSearch('');
                      setStatus('');
                    })
                  }
                >
                  Quitar filtros
                </Button>
              )
            }
          />
        )}

        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              {canUpdate && (
                <Th className="w-10 pr-0">
                  <SelectAll ids={rows.map((user) => user.id)} selected={selected} onChange={setSelected} />
                </Th>
              )}
              <Th>Usuario</Th>
              <Th>Roles</Th>
              <Th>Estado</Th>
              <Th>Último acceso</Th>
              {canUpdate && <Th />}
            </THead>
            <TBody>
              {rows.map((user) => (
                <Tr key={user.id} className={selected.includes(user.id) ? 'bg-brand-50/40' : ''}>
                  {canUpdate && (
                    <Td className="w-10 pr-0">
                      <SelectRow id={user.id} label={`${user.first_name} ${user.last_name}`} selected={selected} onChange={setSelected} />
                    </Td>
                  )}
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={`${user.first_name} ${user.last_name}`} />
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-900 truncate">
                          {user.first_name} {user.last_name}
                        </p>
                        <p className="text-xs text-slate-500 truncate">{user.email}</p>
                      </div>
                    </div>
                  </Td>
                  <Td>
                    <div className="flex flex-wrap gap-1.5">
                      {user.roles.map((role) => (
                        <Badge key={role} tone="brand">
                          {role}
                        </Badge>
                      ))}
                    </div>
                  </Td>
                  <Td>
                    {user.invited_at && user.status === 'ACTIVE' ? (
                      <Badge tone="warning" dot>
                        Invitación pendiente
                      </Badge>
                    ) : (
                      <Badge tone={user.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                        {STATUS_LABEL[user.status]}
                      </Badge>
                    )}
                  </Td>
                  <Td className="text-slate-500 whitespace-nowrap tnum">{formatDate(user.last_login_at)}</Td>
                  {canUpdate && (
                    <Td className="text-right">
                      <Button
                        size="sm"
                        icon={<Pencil className="w-3.5 h-3.5" />}
                        onClick={() => setEditing(user)}
                        disabled={!canPickRoles}
                        title={canPickRoles ? undefined : 'Necesitas el permiso para consultar roles'}
                      >
                        Editar
                      </Button>
                    </Td>
                  )}
                </Tr>
              ))}
            </TBody>
          </Table>
        )}

        {state === 'ready' && meta && (
          <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="usuarios" onPage={setPage} />
        )}
      </Card>

        </>
      )}

      {importing && (
        <ImportModal
          title="Importar personas"
          intro="Crea las cuentas que falten y, en la misma pasada, las matricula en un curso y las suma a un grupo. Quien ya tiene cuenta solo se matricula."
          basePath="/imports/people"
          templateName="plantilla-personas-y-matriculas.xlsx"
          onClose={() => setImporting(false)}
          onImported={load}
        />
      )}

      {bulk && (
        <ConfirmModal
          title={bulk === 'ACTIVE' ? 'Activar usuarios' : 'Desactivar usuarios'}
          text={
            bulk === 'ACTIVE'
              ? `${selected.length === 1 ? 'La cuenta elegida podrá' : `Las ${selected.length} cuentas elegidas podrán`} volver a iniciar sesión.`
              : `${selected.length === 1 ? 'La cuenta elegida dejará' : `Las ${selected.length} cuentas elegidas dejarán`} de poder iniciar sesión y se cerrarán sus sesiones abiertas. Tu propia cuenta y la del último administrador no se desactivan.`
          }
          confirmLabel={bulk === 'ACTIVE' ? 'Activar' : 'Desactivar'}
          variant={bulk === 'ACTIVE' ? 'primary' : 'danger'}
          onClose={() => setBulk(null)}
          onConfirm={() => applyBulk(bulk)}
        />
      )}

      {editing && (
        <UserForm
          user={editing === 'new' ? null : editing}
          roles={roles}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            load();
          }}
        />
      )}
    </div>
  );
};
