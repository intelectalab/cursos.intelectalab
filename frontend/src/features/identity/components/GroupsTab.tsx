import React, { useCallback, useEffect, useState } from 'react';
import { Pencil, Plus, Search, Trash2, UserMinus, UserPlus, UsersRound } from 'lucide-react';
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
} from '../../../shared/ui';
import { SelectAll } from '../../../shared/ui/filters';
import { identityApi, type Group, type GroupDetail, type UserRow } from '../api';

/* ───────────────────────────── Crear o renombrar ───────────────────────────── */

const GroupFormModal: React.FC<{ group: Group | null; onClose: () => void; onSaved: (group: GroupDetail) => void }> = ({ group, onClose, onSaved }) => {
  const [name, setName] = useState(group?.name ?? '');
  const [description, setDescription] = useState(group?.description ?? '');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      const input = { name: name.trim(), description: description.trim() || null };
      onSaved(group ? await identityApi.updateGroup(group.id, input) : await identityApi.createGroup(input));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el grupo.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={group ? 'Editar grupo' : 'Nuevo grupo'}
      subtitle="Una lista de personas con nombre que armas una vez y reutilizas, por ejemplo para matricularlas juntas."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="group-form" processing={processing}>
            {group ? 'Guardar cambios' : 'Crear grupo'}
          </Button>
        </>
      }
    >
      <form id="group-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Nombre" hint="Ejemplos: Promoción 2026-II, Área de ventas, Sede Arequipa.">
          <input className={INPUT_CLASS} required maxLength={120} autoFocus value={name} onChange={(event) => setName(event.target.value)} />
        </Field>
        <Field label="Descripción (opcional)">
          <input className={INPUT_CLASS} maxLength={300} value={description} onChange={(event) => setDescription(event.target.value)} />
        </Field>
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Agregar miembros ───────────────────────────── */

const AddMembersModal: React.FC<{ group: GroupDetail; onClose: () => void; onSaved: (group: GroupDetail) => void }> = ({ group, onClose, onSaved }) => {
  const [search, setSearch] = useState('');
  const [users, setUsers] = useState<UserRow[] | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    const timer = setTimeout(() => {
      identityApi
        .listUsers({ search: search || undefined, status: 'ACTIVE', per_page: 100 })
        .then((result) => active && setUsers(result.data))
        .catch(() => active && setUsers([]));
    }, 250);

    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [search]);

  const members = new Set(group.members.map((member) => member.id));
  const candidates = (users ?? []).filter((user) => !members.has(user.id));

  const submit = async () => {
    setProcessing(true);
    setError(null);
    try {
      onSaved(await identityApi.addGroupMembers(group.id, selected));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo agregar a las personas elegidas.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={`Agregar personas a «${group.name}»`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" processing={processing} disabled={selected.length === 0} onClick={submit}>
            {selected.length > 0 ? `Agregar (${selected.length})` : 'Agregar'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {error && <Alert>{error}</Alert>}
        <div className="relative">
          <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            className={`${INPUT_CLASS} pl-9`}
            placeholder="Buscar por nombre o correo"
            aria-label="Buscar personas"
            autoFocus
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
        {users === null ? (
          <LoadingState />
        ) : candidates.length === 0 ? (
          <EmptyState icon={UsersRound} title={search ? 'Sin resultados' : 'Todos los usuarios activos ya están en este grupo'} />
        ) : (
          <>
            <div className="px-3 py-2 rounded-t-xl border border-b-0 border-slate-200 bg-slate-50/80">
              <SelectAll ids={candidates.map((user) => user.id)} selected={selected} onChange={setSelected}>
                Seleccionar todos ({candidates.length})
              </SelectAll>
            </div>
            <ul className="!mt-0 rounded-b-xl border border-slate-200 divide-y divide-slate-100 max-h-72 overflow-y-auto">
              {candidates.map((user) => (
                <li key={user.id}>
                  <label className="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-brand-50/30">
                    <input
                      type="checkbox"
                      className={CHECKBOX_CLASS}
                      checked={selected.includes(user.id)}
                      onChange={() => setSelected((current) => (current.includes(user.id) ? current.filter((id) => id !== user.id) : [...current, user.id]))}
                    />
                    <Avatar name={`${user.first_name} ${user.last_name}`} size="sm" />
                    <span className="min-w-0 flex-1">
                      <span className="block text-[13px] font-semibold text-slate-900 truncate">
                        {user.first_name} {user.last_name}
                      </span>
                      <span className="block text-xs text-slate-500 truncate">{user.email}</span>
                    </span>
                    <span className="hidden sm:block text-xs text-slate-500">{user.roles.join(', ')}</span>
                  </label>
                </li>
              ))}
            </ul>
          </>
        )}
      </div>
    </Modal>
  );
};

/* ───────────────────────────── Pestaña ───────────────────────────── */

type Dialog = { kind: 'form'; group: Group | null } | { kind: 'members' } | { kind: 'delete' } | { kind: 'remove'; id: string; name: string };

// Grupos: listas de personas reutilizables. Se matriculan completos desde un curso.
export const GroupsTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const [groups, setGroups] = useState<Group[] | null>(null);
  const [open, setOpen] = useState<GroupDetail | null>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [error, setError] = useState(false);

  const load = useCallback(async () => {
    try {
      setGroups(await identityApi.listGroups());
      setError(false);
    } catch {
      setError(true);
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const select = async (group: Group) => {
    try {
      setOpen(await identityApi.group(group.id));
    } catch {
      setError(true);
    }
  };

  const saved = async (group: GroupDetail) => {
    setOpen(group);
    setDialog(null);
    await load();
  };

  if (error) {
    return (
      <Card>
        <ErrorState message="No se pudieron cargar los grupos." onRetry={load} />
      </Card>
    );
  }

  return (
    <div className="grid grid-cols-1 lg:grid-cols-[20rem_1fr] gap-5 items-start">
      <Card>
        <CardHeader
          title="Grupos"
          text="Listas de personas que reutilizas."
          action={
            canManage && (
              <Button size="sm" variant="primary" icon={<Plus className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'form', group: null })}>
                Nuevo
              </Button>
            )
          }
        />
        {groups === null && <LoadingState />}
        {groups?.length === 0 && (
          <EmptyState icon={UsersRound} title="Aún no hay grupos" text="Crea uno para matricular a varias personas de una vez. También se crean solos al importar personas con la columna Grupo." />
        )}
        {groups && groups.length > 0 && (
          <ul className="divide-y divide-slate-100">
            {groups.map((group) => (
              <li key={group.id}>
                <button
                  type="button"
                  aria-current={open?.id === group.id}
                  onClick={() => select(group)}
                  className={`w-full flex items-center justify-between gap-3 px-5 py-3 text-left cursor-pointer transition-colors ${
                    open?.id === group.id ? 'bg-brand-50/70' : 'hover:bg-slate-50'
                  }`}
                >
                  <span className="min-w-0">
                    <span className="block text-[13px] font-semibold text-slate-900 truncate">{group.name}</span>
                    {group.description && <span className="block text-xs text-slate-500 truncate">{group.description}</span>}
                  </span>
                  <Badge tone="brand">{group.members_count}</Badge>
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        {open === null ? (
          <EmptyState icon={UsersRound} title="Elige un grupo" text="Verás a sus miembros y podrás agregar o quitar personas. Para matricular un grupo completo, entra al curso, pestaña Participantes." />
        ) : (
          <>
            <CardHeader
              title={open.name}
              text={open.description ?? `${open.members_count} ${open.members_count === 1 ? 'persona' : 'personas'}`}
              action={
                canManage && (
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" icon={<Pencil className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'form', group: open })}>
                      Editar
                    </Button>
                    <Button size="sm" variant="danger" icon={<Trash2 className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'delete' })}>
                      Eliminar
                    </Button>
                    <Button size="sm" variant="primary" icon={<UserPlus className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'members' })}>
                      Agregar personas
                    </Button>
                  </div>
                )
              }
            />
            {open.members.length === 0 ? (
              <EmptyState icon={UsersRound} title="Este grupo está vacío" text="Agrega a las personas que lo forman." />
            ) : (
              <ul className="divide-y divide-slate-100">
                {open.members.map((member) => (
                  <li key={member.id} className="flex items-center gap-3 px-5 py-2.5">
                    <Avatar name={member.name} size="sm" />
                    <span className="min-w-0 flex-1">
                      <span className="block text-[13px] font-semibold text-slate-900 truncate">{member.name}</span>
                      <span className="block text-xs text-slate-500 truncate">{member.email}</span>
                    </span>
                    {!member.active && <Badge tone="neutral">Inactivo</Badge>}
                    {canManage && (
                      <Button
                        size="sm"
                        aria-label={`Quitar a ${member.name} del grupo`}
                        icon={<UserMinus className="w-3.5 h-3.5" />}
                        onClick={() => setDialog({ kind: 'remove', id: member.id, name: member.name })}
                      />
                    )}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </Card>

      {dialog?.kind === 'form' && <GroupFormModal group={dialog.group} onClose={() => setDialog(null)} onSaved={saved} />}
      {dialog?.kind === 'members' && open && <AddMembersModal group={open} onClose={() => setDialog(null)} onSaved={saved} />}
      {dialog?.kind === 'remove' && open && (
        <ConfirmModal
          title="Quitar del grupo"
          text={`${dialog.name} saldrá de «${open.name}». Conserva su cuenta y sus matrículas.`}
          confirmLabel="Quitar"
          onClose={() => setDialog(null)}
          onConfirm={async () => saved(await identityApi.removeGroupMember(open.id, dialog.id))}
        />
      )}
      {dialog?.kind === 'delete' && open && (
        <ConfirmModal
          title="Eliminar grupo"
          text={`Se eliminará la lista «${open.name}». Las personas conservan su cuenta y las matrículas que ya tengan.`}
          confirmLabel="Eliminar grupo"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await identityApi.deleteGroup(open.id);
            setOpen(null);
            setDialog(null);
            await load();
          }}
        />
      )}
    </div>
  );
};
