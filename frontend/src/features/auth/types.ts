export interface Tenant {
  id: string;
  name: string;
  slug: string;
}

export interface User {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
  roles: string[];
  // Entró con una contraseña temporal y debe cambiarla antes de seguir
  must_change_password?: boolean;
}

export interface AuthSession {
  token: string;
  user: User;
  tenant: Tenant;
  permissions: string[];
  // Sesión abierta por el soporte de plataforma: solo lectura
  support_mode?: boolean;
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