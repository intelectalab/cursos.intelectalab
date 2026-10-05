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
  async listUsers(params: { search?: string; status?: string; page?: number }) {
    const response = await api.get('/users', { params });
    return response.data as { data: UserRow[]; meta: PageMeta };
  },

  async createUser(input: UserInput & { email: string; password: string; roles: string[] }) {
    return (await api.post('/users', input)).data.data as UserRow;
  },

  async updateUser(id: string, input: UserInput & { status: UserRow['status'] }) {
    return (await api.put(`/users/${id}`, input)).data.data as UserRow;
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
