import React, { useCallback, useEffect, useState } from 'react';
import { Lock, Plus, ShieldCheck, Trash2, Users } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { can } from '../../../shared/auth/permissions';
import {
  Alert,
  Badge,
  Button,
  Card,
  CHECKBOX_CLASS,
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
        icon={ShieldCheck}
        subtitle="Crea roles propios y define a qué módulos accede cada uno. Los roles base no se pueden modificar."
        action={
          canCreate && (
            <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => select(NEW_DRAFT)}>
              Nuevo rol
            </Button>
          )
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-5 items-start">
        <Card className="p-2 lg:sticky lg:top-2">
          <p className="px-3 pt-2 pb-1.5 text-xs font-semibold text-slate-500">Roles de la organización</p>
          <ul className="space-y-0.5">
            {draft?.id === null && (
              <li className="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-brand-50 ring-1 ring-inset ring-brand-200 text-[13px] font-semibold text-brand-800">
                <Plus className="w-4 h-4" />
                Nuevo rol
              </li>
            )}
            {roles.map((role) => {
              const active = draft?.id === role.id;
              return (
                <li key={role.id}>
                  <button
                    type="button"
                    onClick={() => select(toDraft(role))}
                    aria-current={active || undefined}
                    className={`w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl text-left text-[13px] cursor-pointer transition-colors ${
                      active
                        ? 'bg-brand-50 ring-1 ring-inset ring-brand-200 text-brand-800'
                        : 'text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    <span className="min-w-0">
                      <span className="block font-semibold truncate">{role.name}</span>
                      <span className="flex items-center gap-1 text-xs text-slate-500 mt-0.5">
                        <Users className="w-3 h-3" />
                        {role.users_count} {role.users_count === 1 ? 'usuario' : 'usuarios'}
                      </span>
                    </span>
                    {role.is_system ? <Badge>Base</Badge> : <Badge tone="violet">Propio</Badge>}
                  </button>
                </li>
              );
            })}
          </ul>
        </Card>

        {!draft ? (
          <Card>
            <EmptyState icon={ShieldCheck} title="Selecciona un rol" text="Elige un rol de la lista o crea uno nuevo." />
          </Card>
        ) : (
          <form onSubmit={handleSave} className="space-y-5">
            <Card className="p-5 space-y-4">
              {isSystem && (
                <div className="flex items-start gap-2.5 px-3.5 py-3 bg-slate-50 border border-slate-200 rounded-xl text-[13px] text-slate-600">
                  <Lock className="w-4 h-4 shrink-0 mt-0.5 text-slate-500" />
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
              <Card className="relative overflow-hidden p-6">
                <div className="absolute -top-16 -right-10 w-56 h-56 rounded-full bg-sky-400/15 blur-3xl pointer-events-none" />
                <div className="relative flex items-center gap-3.5">
                  <span className="w-11 h-11 shrink-0 rounded-xl bg-gradient-to-br from-brand-700 to-sky-500 text-white flex items-center justify-center shadow-md shadow-brand-700/25">
                    <ShieldCheck className="w-5 h-5" />
                  </span>
                  <div>
                    <p className="text-sm font-semibold text-slate-900">Acceso total</p>
                    <p className="text-[13px] text-slate-500">
                      Este rol puede usar todos los módulos de la organización, incluidos los que se agreguen después.
                    </p>
                  </div>
                </div>
              </Card>
            ) : (
              <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
                {modules.map((module) => {
                  const codes = module.permissions.map((p) => p.code);
                  const selectedCount = codes.filter((code) => draft.permissions.includes(code)).length;
                  const selectable = codes.filter(grantable);
                  const allSelected = selectable.length > 0 && selectable.every((c) => draft.permissions.includes(c));

                  return (
                    <Card key={module.module} className="overflow-hidden">
                      <div className="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-slate-200/80 bg-slate-50/60">
                        <div className="min-w-0">
                          <h3 className="text-[13px] font-semibold text-slate-900">{module.name}</h3>
                          <div className="flex items-center gap-2 mt-1.5">
                            <span className="h-1.5 w-20 rounded-full bg-slate-200 overflow-hidden">
                              <span
                                className="block h-full rounded-full bg-gradient-to-r from-brand-600 to-sky-500 transition-all"
                                style={{ width: `${(selectedCount / codes.length) * 100}%` }}
                              />
                            </span>
                            <span className="text-xs text-slate-500 tnum">
                              {selectedCount} de {codes.length}
                            </span>
                          </div>
                        </div>
                        {editable && selectable.length > 0 && (
                          <label className="flex items-center gap-2 shrink-0 whitespace-nowrap text-xs font-medium text-slate-600 cursor-pointer">
                            <input
                              type="checkbox"
                              className={CHECKBOX_CLASS}
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
                          const checked = draft.permissions.includes(permission.code);
                          return (
                            <li key={permission.code}>
                              <label
                                className={`flex items-start gap-2.5 px-3 py-2 rounded-lg text-[13px] transition-colors ${
                                  editable && allowed ? 'cursor-pointer hover:bg-brand-50/50' : ''
                                }`}
                                title={editable && !allowed ? 'No puedes otorgar un permiso que tu usuario no tiene' : undefined}
                              >
                                <input
                                  type="checkbox"
                                  className={`${CHECKBOX_CLASS} mt-0.5`}
                                  disabled={!editable || !allowed}
                                  checked={checked}
                                  onChange={() => toggle(permission.code)}
                                />
                                <span
                                  className={
                                    editable && !allowed
                                      ? 'text-slate-500'
                                      : checked
                                        ? 'text-slate-900 font-medium'
                                        : 'text-slate-600'
                                  }
                                >
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
              <div className="sticky bottom-0 flex items-center justify-between gap-3 px-5 py-3.5 bg-white/90 backdrop-blur border border-slate-200/80 rounded-2xl shadow-raised">
                <div>
                  {draft.id && canDelete && (
                    <Button
                      variant="danger"
                      icon={<Trash2 className="w-4 h-4" />}
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
