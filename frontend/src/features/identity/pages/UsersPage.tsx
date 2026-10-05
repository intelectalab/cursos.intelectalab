import React, { useCallback, useEffect, useState } from 'react';
import { Pencil, Plus, Search } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  PageHeader,
} from '../../../shared/ui';
import { identityApi, type PageMeta, type Role, type UserRow } from '../api';

const STATUS_LABEL: Record<UserRow['status'], string> = { ACTIVE: 'Activo', INACTIVE: 'Inactivo' };

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' }) : 'Nunca';
}

interface FormState {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  password: string;
  status: UserRow['status'];
  roles: string[];
}

const EMPTY_FORM: FormState = {
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  password: '',
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
      ? { ...EMPTY_FORM, ...user, phone: user.phone ?? '', roles: user.role_ids }
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

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (form.roles.length === 0) {
      setError('Selecciona al menos un rol.');
      return;
    }

    setProcessing(true);
    setError(null);

    const base = { first_name: form.first_name, last_name: form.last_name, phone: form.phone || null };

    try {
      if (user) {
        await identityApi.updateUser(user.id, { ...base, status: form.status });
        const rolesChanged = [...form.roles].sort().join() !== [...user.role_ids].sort().join();
        if (rolesChanged) await identityApi.setUserRoles(user.id, form.roles);
      } else {
        await identityApi.createUser({ ...base, email: form.email, password: form.password, roles: form.roles });
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
      <form id="user-form" onSubmit={handleSubmit} className="space-y-3.5">
        {error && <Alert>{error}</Alert>}

        <div className="grid grid-cols-2 gap-3">
          <Field label="Nombres">
            <input className={INPUT_CLASS} required maxLength={100} value={form.first_name} onChange={set('first_name')} />
          </Field>
          <Field label="Apellidos">
            <input className={INPUT_CLASS} required maxLength={100} value={form.last_name} onChange={set('last_name')} />
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

        <div className="grid grid-cols-2 gap-3">
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
          ) : (
            <Field label="Contraseña inicial" hint="Mínimo 8 caracteres.">
              <input
                className={INPUT_CLASS}
                type="password"
                required
                minLength={8}
                autoComplete="new-password"
                value={form.password}
                onChange={set('password')}
              />
            </Field>
          )}
        </div>

        <fieldset>
          <legend className="block text-[11px] font-semibold text-slate-700 mb-1.5">Roles</legend>
          <div className="grid grid-cols-2 gap-2">
            {roles.map((role) => (
              <label
                key={role.id}
                className="flex items-center gap-2 px-3 py-2 border border-slate-200 rounded-lg text-xs text-slate-700 cursor-pointer hover:bg-slate-50"
              >
                <input
                  type="checkbox"
                  className="accent-[#084586]"
                  checked={form.roles.includes(role.id)}
                  onChange={() => toggleRole(role.id)}
                />
                <span className="truncate">{role.name}</span>
              </label>
            ))}
          </div>
        </fieldset>
      </form>
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

  return (
    <div>
      <PageHeader
        title="Usuarios"
        subtitle="Personas con acceso a tu organización y los roles que tienen asignados."
        action={
          canCreate &&
          canPickRoles && (
            <Button variant="primary" icon={<Plus className="w-3.5 h-3.5" />} onClick={() => setEditing('new')}>
              Nuevo usuario
            </Button>
          )
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 p-4 border-b border-slate-200">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por nombre o correo"
              aria-label="Buscar usuarios"
              value={search}
              onChange={(event) => {
                setSearch(event.target.value);
                setPage(1);
              }}
            />
          </div>
          <select
            className={`${INPUT_CLASS} w-auto`}
            aria-label="Filtrar por estado"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setPage(1);
            }}
          >
            <option value="">Todos los estados</option>
            <option value="ACTIVE">Activos</option>
            <option value="INACTIVE">Inactivos</option>
          </select>
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            title={filtered ? 'Sin resultados' : 'Aún no hay usuarios'}
            text={filtered ? 'Prueba con otra búsqueda o cambia el filtro de estado.' : undefined}
          />
        )}

        {state === 'ready' && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2.5 font-semibold">Nombre</th>
                  <th className="px-4 py-2.5 font-semibold">Correo</th>
                  <th className="px-4 py-2.5 font-semibold">Roles</th>
                  <th className="px-4 py-2.5 font-semibold">Estado</th>
                  <th className="px-4 py-2.5 font-semibold">Último acceso</th>
                  {canUpdate && <th className="px-4 py-2.5" />}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((user) => (
                  <tr key={user.id} className="hover:bg-slate-50/60">
                    <td className="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">
                      {user.first_name} {user.last_name}
                    </td>
                    <td className="px-4 py-3 text-slate-600">{user.email}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        {user.roles.map((role) => (
                          <Badge key={role} tone="info">
                            {role}
                          </Badge>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={user.status === 'ACTIVE' ? 'success' : 'neutral'}>{STATUS_LABEL[user.status]}</Badge>
                    </td>
                    <td className="px-4 py-3 text-slate-500 whitespace-nowrap">{formatDate(user.last_login_at)}</td>
                    {canUpdate && (
                      <td className="px-4 py-3 text-right">
                        <Button
                          icon={<Pencil className="w-3 h-3" />}
                          onClick={() => setEditing(user)}
                          disabled={!canPickRoles}
                          title={canPickRoles ? undefined : 'Necesitas el permiso para consultar roles'}
                        >
                          Editar
                        </Button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {state === 'ready' && meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200 text-xs text-slate-500">
            <span>
              Página {meta.page} de {meta.last_page} · {meta.total} usuarios
            </span>
            <div className="flex gap-2">
              <Button disabled={meta.page <= 1} onClick={() => setPage(meta.page - 1)}>
                Anterior
              </Button>
              <Button disabled={meta.page >= meta.last_page} onClick={() => setPage(meta.page + 1)}>
                Siguiente
              </Button>
            </div>
          </div>
        )}
      </Card>

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
