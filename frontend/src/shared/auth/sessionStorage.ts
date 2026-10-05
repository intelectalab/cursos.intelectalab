import type { AuthSession } from '../../features/auth/types';

// localStorage es por origen: cada subdominio guarda su propia sesión.
const KEY = 'erp_session';
// Sesión del administrador de plataforma (solo existe en el dominio central)
const PLATFORM_KEY = 'erp_platform_session';

export interface PlatformSession {
  token: string;
  admin: { id: string; name: string; email: string };
}

function read<T>(key: string): T | null {
  try {
    const saved = localStorage.getItem(key);
    return saved ? (JSON.parse(saved) as T) : null;
  } catch {
    return null;
  }
}

export const loadSession = () => read<AuthSession>(KEY);

export function saveSession(session: AuthSession): void {
  localStorage.setItem(KEY, JSON.stringify(session));
}

export function clearSession(): void {
  localStorage.removeItem(KEY);
}

export const loadPlatformSession = () => read<PlatformSession>(PLATFORM_KEY);

export function savePlatformSession(session: PlatformSession): void {
  localStorage.setItem(PLATFORM_KEY, JSON.stringify(session));
}

export function clearPlatformSession(): void {
  localStorage.removeItem(PLATFORM_KEY);
}

// Token de la sesión vigente en este origen, sea de academia o de plataforma
export function currentToken(): string | null {
  return loadSession()?.token ?? loadPlatformSession()?.token ?? null;
}
