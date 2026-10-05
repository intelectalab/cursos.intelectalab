import React, { useCallback, useEffect, useState } from 'react';
import { Pencil, Plus, Search, UserCog, Users } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
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
import { DOCUMENT_TYPES, identityApi, type DocumentType, type PageMeta, type Role, type UserRow } from '../api';

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

        <div className="grid grid-cols-2 gap-3">
          <Field label="Nombres">
            <input className={INPUT_CLASS} required maxLength={100} value={form.first_name} onChange={set('first_name')} />
          </Field>
          <Field label="Apellidos">
            <input className={INPUT_CLASS} required maxLength={100} value={form.last_name} onChange={set('last_name')} />
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-3">
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
          <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Roles</legend>
          <div className="grid grid-cols-2 gap-2">
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
        icon={UserCog}
        subtitle="Personas con acceso a tu organización y los roles que tienen asignados."
        action={
          canCreate &&
          canPickRoles && (
            <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setEditing('new')}>
              Nuevo usuario
            </Button>
          )
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
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
            className={`${INPUT_CLASS} !w-48`}
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
            icon={Users}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay usuarios'}
            text={filtered ? 'Prueba con otra búsqueda o cambia el filtro de estado.' : undefined}
            action={
              filtered && (
                <Button
                  onClick={() => {
                    setSearch('');
                    setStatus('');
                    setPage(1);
                  }}
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
              <Th>Usuario</Th>
              <Th>Roles</Th>
              <Th>Estado</Th>
              <Th>Último acceso</Th>
              {canUpdate && <Th />}
            </THead>
            <TBody>
              {rows.map((user) => (
                <Tr key={user.id}>
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
                    <Badge tone={user.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                      {STATUS_LABEL[user.status]}
                    </Badge>
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
