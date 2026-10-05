import React, { useCallback, useEffect, useState } from 'react';
import { Lock, Plus, Trash2 } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { can } from '../../../shared/auth/permissions';
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
  PageHeader,
} from '../../../shared/ui';
import { identityApi, type PermissionModule, type Role } from '../api';

interface Draft {
  id: string | null;
  name: string;
  description: string;
  permissions: string[];
}

const NEW_DRAFT: Draft = { id: null, name: '', description: '', permissions: [] };

const toDraft = (role: Role): Draft => ({
  id: role.id,
  name: role.name,
  description: role.description ?? '',
  permissions: role.permissions,
});

const fetchAll = () => Promise.all([identityApi.listRoles(), identityApi.listPermissions()]);

export const RolesPage: React.FC = () => {
  const { permissions: ownPermissions, hasPermission } = useAuth();
  const canCreate = hasPermission('roles.create');
  const canUpdate = hasPermission('roles.update');
  const canDelete = hasPermission('roles.delete');

  const [roles, setRoles] = useState<Role[]>([]);
  const [modules, setModules] = useState<PermissionModule[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [draft, setDraft] = useState<Draft | null>(null);
  const [processing, setProcessing] = useState<'save' | 'delete' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const apply = useCallback(([roleList, moduleList]: [Role[], PermissionModule[]], selectId?: string | null) => {
    setRoles(roleList);
    setModules(moduleList);
    setState('ready');

    const selected = roleList.find((role) => role.id === selectId) ?? roleList[0];
    setDraft(selected ? toDraft(selected) : null);
  }, []);

  const fail = useCallback((err: unknown) => {
    setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
    setState('error');
  }, []);

  const load = useCallback(
    (selectId?: string | null) => fetchAll().then((data) => apply(data, selectId)),
    [apply],
  );

  useEffect(() => {
    let active = true;
    fetchAll()
      .then((data) => active && apply(data))
      .catch((err) => active && fail(err));

    return () => {
      active = false;
    };
  }, [apply, fail]);

  if (state === 'loading') return <LoadingState />;
  if (state === 'error') {
    return (
      <ErrorState
        message={loadError}
        onRetry={() => {
          setState('loading');
          load().catch(fail);
        }}
      />
    );
  }

  const selectedRole = roles.find((role) => role.id === draft?.id) ?? null;
  const isSystem = selectedRole?.is_system ?? false;
  const hasFullAccess = selectedRole?.permissions.includes('*') ?? false;
  const editable = draft !== null && !isSystem && (draft.id === null ? canCreate : canUpdate);

  const select = (next: Draft) => {
    setDraft(next);
    setError(null);
  };

  const toggle = (code: string) =>
    setDraft((prev) =>
      prev && {
        ...prev,
        permissions: prev.permissions.includes(code)
          ? prev.permissions.filter((p) => p !== code)
          : [...prev.permissions, code],
      },
    );

  // Solo se pueden marcar los permisos que el propio usuario tiene
  const grantable = (code: string) => can(ownPermissions, code);

  const toggleModule = (module: PermissionModule, checked: boolean) => {
    const codes = module.permissions.map((p) => p.code).filter(grantable);
    setDraft((prev) =>
      prev && {
        ...prev,
        permissions: checked
          ? [...new Set([...prev.permissions, ...codes])]
          : prev.permissions.filter((p) => !codes.includes(p)),
      },
    );
  };

  const handleSave = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!draft) return;

    setProcessing('save');
    setError(null);

    const input = { name: draft.name, description: draft.description || null, permissions: draft.permissions };

    try {
      const saved = draft.id ? await identityApi.updateRole(draft.id, input) : await identityApi.createRole(input);
      await load(saved.id);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el rol.'));
    } finally {
      setProcessing(null);
    }
  };

  const handleDelete = async () => {
    if (!draft?.id || !window.confirm(`¿Eliminar el rol "${draft.name}"? Esta acción no se puede deshacer.`)) return;

    setProcessing('delete');
    setError(null);

    try {
      await identityApi.deleteRole(draft.id);
      await load();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo eliminar el rol.'));
    } finally {
      setProcessing(null);
    }
  };

  return (
    <div>
      <PageHeader
        title="Roles y permisos"
        subtitle="Crea roles propios y define a qué módulos accede cada uno. Los roles base no se pueden modificar."
        action={
          canCreate && (
            <Button variant="primary" icon={<Plus className="w-3.5 h-3.5" />} onClick={() => select(NEW_DRAFT)}>
              Nuevo rol
            </Button>
          )
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-4 items-start">
        <Card className="p-2">
          <ul className="space-y-0.5">
            {draft?.id === null && (
              <li className="px-3 py-2.5 rounded-lg bg-[#084586]/10 text-xs font-semibold text-[#084586]">
                Nuevo rol
              </li>
            )}
            {roles.map((role) => (
              <li key={role.id}>
                <button
                  type="button"
                  onClick={() => select(toDraft(role))}
                  className={`w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg text-left text-xs cursor-pointer transition-colors ${
                    draft?.id === role.id ? 'bg-[#084586]/10 text-[#084586]' : 'text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <span className="min-w-0">
                    <span className="block font-semibold truncate">{role.name}</span>
                    <span className="block text-[11px] text-slate-500">
                      {role.users_count} {role.users_count === 1 ? 'usuario' : 'usuarios'}
                    </span>
                  </span>
                  {role.is_system && <Badge>Base</Badge>}
                </button>
              </li>
            ))}
          </ul>
        </Card>

        {!draft ? (
          <Card>
            <EmptyState title="Selecciona un rol" />
          </Card>
        ) : (
          <form onSubmit={handleSave} className="space-y-4">
            <Card className="p-5 space-y-3.5">
              {isSystem && (
                <div className="flex items-start gap-2 p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-600">
                  <Lock className="w-4 h-4 shrink-0 mt-0.5 text-slate-400" />
                  <span>
                    Este es un rol base de la plataforma: no se puede modificar ni eliminar. Para un acceso distinto,
                    crea un rol propio.
                  </span>
                </div>
              )}
              {error && <Alert>{error}</Alert>}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Field label="Nombre del rol">
                  <input
                    className={INPUT_CLASS}
                    required
                    maxLength={80}
                    disabled={!editable}
                    value={draft.name}
                    onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                  />
                </Field>
                <Field label="Descripción (opcional)">
                  <input
                    className={INPUT_CLASS}
                    maxLength={255}
                    disabled={!editable}
                    value={draft.description}
                    onChange={(event) => setDraft({ ...draft, description: event.target.value })}
                  />
                </Field>
              </div>
            </Card>

            {hasFullAccess ? (
              <Card className="p-5 text-xs text-slate-600">
                Este rol tiene <strong className="text-slate-900">acceso total</strong> a todos los módulos de la
                organización.
              </Card>
            ) : (
              <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
                {modules.map((module) => {
                  const codes = module.permissions.map((p) => p.code);
                  const selectedCount = codes.filter((code) => draft.permissions.includes(code)).length;
                  const selectable = codes.filter(grantable);
                  const allSelected = selectable.length > 0 && selectable.every((c) => draft.permissions.includes(c));

                  return (
                    <Card key={module.module}>
                      <div className="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-200">
                        <div>
                          <h3 className="text-xs font-bold text-slate-900">{module.name}</h3>
                          <p className="text-[11px] text-slate-500">
                            {selectedCount} de {codes.length} permisos
                          </p>
                        </div>
                        {editable && selectable.length > 0 && (
                          <label className="flex items-center gap-1.5 text-[11px] font-medium text-slate-600 cursor-pointer">
                            <input
                              type="checkbox"
                              className="accent-[#084586]"
                              checked={allSelected}
                              onChange={(event) => toggleModule(module, event.target.checked)}
                            />
                            Todo el módulo
                          </label>
                        )}
                      </div>
                      <ul className="p-2">
                        {module.permissions.map((permission) => {
                          const allowed = grantable(permission.code);
                          return (
                            <li key={permission.code}>
                              <label
                                className={`flex items-start gap-2.5 px-2 py-1.5 rounded-md text-xs ${
                                  editable && allowed ? 'cursor-pointer hover:bg-slate-50' : ''
                                }`}
                                title={editable && !allowed ? 'No puedes otorgar un permiso que tu usuario no tiene' : undefined}
                              >
                                <input
                                  type="checkbox"
                                  className="accent-[#084586] mt-0.5"
                                  disabled={!editable || !allowed}
                                  checked={draft.permissions.includes(permission.code)}
                                  onChange={() => toggle(permission.code)}
                                />
                                <span className={editable && !allowed ? 'text-slate-400' : 'text-slate-700'}>
                                  {permission.description}
                                </span>
                              </label>
                            </li>
                          );
                        })}
                      </ul>
                    </Card>
                  );
                })}
              </div>
            )}

            {editable && (
              <div className="flex items-center justify-between gap-3">
                <div>
                  {draft.id && canDelete && (
                    <Button
                      variant="danger"
                      icon={<Trash2 className="w-3.5 h-3.5" />}
                      onClick={handleDelete}
                      processing={processing === 'delete'}
                      disabled={processing !== null}
                    >
                      Eliminar rol
                    </Button>
                  )}
                </div>
                <Button variant="primary" type="submit" processing={processing === 'save'} disabled={processing !== null}>
                  {draft.id ? 'Guardar cambios' : 'Crear rol'}
                </Button>
              </div>
            )}
          </form>
        )}
      </div>
    </div>
  );
};
