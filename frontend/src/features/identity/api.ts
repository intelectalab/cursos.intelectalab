import { api } from '../../shared/api/client';

export type DocumentType = 'DNI' | 'CE' | 'PASAPORTE';

export const DOCUMENT_TYPES: Record<DocumentType, string> = {
  DNI: 'DNI',
  CE: 'Carné de extranjería',
  PASAPORTE: 'Pasaporte',
};

export interface UserRow {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
  phone: string | null;
  doc_type: DocumentType | null;
  doc_number: string | null;
  status: 'ACTIVE' | 'INACTIVE';
  roles: string[];
  role_ids: string[];
  last_login_at: string | null;
}

export interface Role {
  id: string;
  code: string;
  name: string;
  description: string | null;
  status: string;
  is_system: boolean;
  permissions: string[];
  users_count: number;
}

export interface PermissionModule {
  module: string;
  name: string;
  permissions: { code: string; description: string }[];
}

export interface PageMeta {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
  // Cuántos hay en cada estado con la búsqueda actual (ALL = total); alimenta las tarjetas que filtran
  counts?: Record<string, number>;
  expiring_days?: number;
}

// Lista de personas reutilizable
export interface Group {
  id: string;
  name: string;
  description: string | null;
  members_count: number;
}

export interface GroupDetail extends Group {
  members: { id: string; name: string; email: string; active: boolean }[];
}

export interface UserInput {
  first_name: string;
  last_name: string;
  phone: string | null;
  doc_type: DocumentType | null;
  doc_number: string | null;
}

export interface RoleInput {
  name: string;
  description: string | null;
  permissions: string[];
}

export const identityApi = {
  async listGroups() {
    return (await api.get('/groups')).data.data as Group[];
  },
  async group(id: string) {
    return (await api.get(`/groups/${id}`)).data.data as GroupDetail;
  },
  async createGroup(input: { name: string; description: string | null }) {
    return (await api.post('/groups', input)).data.data as GroupDetail;
  },
  async updateGroup(id: string, input: { name: string; description: string | null }) {
    return (await api.put(`/groups/${id}`, input)).data.data as GroupDetail;
  },
  deleteGroup: (id: string) => api.delete(`/groups/${id}`),
  async addGroupMembers(id: string, userIds: string[]) {
    return (await api.post(`/groups/${id}/members`, { user_ids: userIds })).data.data as GroupDetail;
  },
  async removeGroupMember(id: string, userId: string) {
    return (await api.delete(`/groups/${id}/members/${userId}`)).data.data as GroupDetail;
  },

  async listUsers(params: { search?: string; status?: string; page?: number; per_page?: number }) {
    const response = await api.get('/users', { params });
    return response.data as { data: UserRow[]; meta: PageMeta };
  },

  async createUser(input: UserInput & { email: string; password: string; roles: string[] }) {
    return (await api.post('/users', input)).data.data as UserRow;
  },

  async updateUser(id: string, input: UserInput & { status: UserRow['status'] }) {
    return (await api.put(`/users/${id}`, input)).data.data as UserRow;
  },

  // Activa o desactiva varias cuentas; las que no se pudieron cambiar vuelven con su motivo
  async setUsersStatus(ids: string[], status: UserRow['status']) {
    return (await api.post('/users/bulk-status', { user_ids: ids, status })).data.data as {
      updated: number;
      skipped: { email: string; reason: string }[];
    };
  },

  // Cierra las sesiones abiertas de la persona; devuelve cuántas eran
  async revokeUserSessions(id: string) {
    return (await api.delete(`/users/${id}/sessions`)).data.data.revoked as number;
  },

  async setUserRoles(id: string, roles: string[]) {
    return (await api.put(`/users/${id}/roles`, { roles })).data.data as UserRow;
  },

  async listRoles() {
    return (await api.get('/roles')).data.data as Role[];
  },

  async listPermissions() {
    return (await api.get('/permissions')).data.data as PermissionModule[];
  },

  async createRole(input: RoleInput) {
    return (await api.post('/roles', input)).data.data as Role;
  },

  async updateRole(id: string, input: RoleInput) {
    return (await api.put(`/roles/${id}`, input)).data.data as Role;
  },

  async deleteRole(id: string) {
    await api.delete(`/roles/${id}`);
  },
};
