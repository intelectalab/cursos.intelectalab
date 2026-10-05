import type { AuthSession } from '../../features/auth/types';

// localStorage es por origen: cada subdominio guarda su propia sesión.
const KEY = 'erp_session';

export function loadSession(): AuthSession | null {
  try {
    const saved = localStorage.getItem(KEY);
    return saved ? (JSON.parse(saved) as AuthSession) : null;
  } catch {
    return null;
  }
}

export function saveSession(session: AuthSession): void {
  localStorage.setItem(KEY, JSON.stringify(session));
}

export function clearSession(): void {
  localStorage.removeItem(KEY);
}
