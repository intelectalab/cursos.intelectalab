export interface Tenant {
  id: string;
  name: string;
}

export interface User {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
  roles: string[];
}

export interface AuthSession {
  token: string;
  user: User;
  tenant: Tenant;
  permissions: string[];
}

export type UIState = 
  | 'idle' 
  | 'loading' 
  | 'processing' 
  | 'success' 
  | 'error' 
  | 'validation_error' 
  | 'empty' 
  | 'no_permission' 
  | 'not_found' 
  | 'disabled' 
  | 'offline';