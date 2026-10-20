import axios from 'axios';
import { currentToken } from '../auth/sessionStorage';
import { pathTenant } from '../tenancy/hosts';

// Formato de error estándar de la API
export interface ApiError {
  code: string;
  message: string;
  details: Record<string, unknown> | null;
  trace_id: string | null;
}

export const UNAUTHORIZED_EVENT = 'erp:unauthorized';

// Mismo origen: el subdominio identifica a la organización en el backend.
// En el modo por ruta (/a/{slug}) todas comparten el dominio: la organización va en X-Tenant.
export const api = axios.create({
  baseURL: '/api/v1',
  headers: {
    Accept: 'application/json',
    ...(pathTenant ? { 'X-Tenant': pathTenant } : {}),
  },
});

// Tiempo máximo de espera: sin él, una petición sin respuesta deja la pantalla cargando para siempre.
// Subir archivos y descargar reportes tarda más con conexiones lentas.
const TIMEOUT_MS = 30_000;
const UPLOAD_TIMEOUT_MS = 5 * 60_000;
const DOWNLOAD_TIMEOUT_MS = 2 * 60_000;

api.interceptors.request.use((config) => {
  const token = currentToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  if (!config.timeout) {
    config.timeout = config.data instanceof FormData ? UPLOAD_TIMEOUT_MS : config.responseType === 'blob' ? DOWNLOAD_TIMEOUT_MS : TIMEOUT_MS;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    // Token vencido o revocado: se cierra la sesión local
    if (axios.isAxiosError(error) && error.response?.status === 401 && currentToken()) {
      window.dispatchEvent(new Event(UNAUTHORIZED_EVENT));
    }
    return Promise.reject(error);
  },
);

export function apiError(error: unknown): ApiError | null {
  if (axios.isAxiosError<ApiError>(error) && error.response?.data?.code) {
    return error.response.data;
  }
  return null;
}

// Lo que falló cuando el servidor no llegó a explicar el error: sin red, sin respuesta o una respuesta que no es de la API.
// Devuelve null si no se reconoce la causa.
function transportProblem(error: unknown): string | null {
  if (!axios.isAxiosError(error)) return null;

  if (!error.response) {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return 'No hay conexión a internet. Revisa tu red e inténtalo de nuevo.';
    if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') return 'El servidor tardó demasiado en responder. Antes de repetir la operación, comprueba si ya se hizo.';
    if (error.code === 'ERR_CANCELED') return null;
    return 'No se pudo conectar con el servidor. Puede ser tu conexión o que el sistema no esté disponible: inténtalo de nuevo en un momento.';
  }

  const status = error.response.status;
  if (status === 401) return 'Tu sesión venció. Vuelve a iniciar sesión.';
  if (status === 413) return 'Lo que intentas enviar es demasiado grande. Reduce el tamaño del archivo e inténtalo de nuevo.';
  if (status === 429) return 'Hiciste demasiados intentos seguidos. Espera un minuto y vuelve a intentarlo.';
  if (status === 502 || status === 503 || status === 504) return 'El sistema no está respondiendo en este momento. Inténtalo de nuevo en unos minutos.';
  if (status >= 500) return `El servidor tuvo un problema (error ${status}). Inténtalo de nuevo; si se repite, avisa a soporte.`;
  return null;
}

// Código corto de la petición: el mismo que queda en el registro del servidor, para ubicar qué pasó
const reference = (traceId: string | null | undefined) => (traceId ? ` Código: ${traceId.slice(0, 8)}.` : '');

// Mensaje para mostrar a la persona. `fallback` nombra la acción que falló y solo se usa si no se sabe nada más.
export function apiErrorMessage(error: unknown, fallback: string): string {
  const data = apiError(error);
  if (!data) return transportProblem(error) ?? fallback;

  if (data.code === 'VALIDATION_ERROR' && data.details) {
    const first = Object.values(data.details)[0];
    if (Array.isArray(first) && typeof first[0] === 'string') return first[0];
  }

  // Un error que el servidor no esperaba, o una falla con un servicio externo: el código permite buscarlo
  const unexpected = axios.isAxiosError(error) && (error.response?.status ?? 0) >= 500;

  return (data.message || fallback) + (unexpected ? reference(data.trace_id) : '');
}

// Claves de las operaciones cuyo resultado no se llegó a conocer (se cortó la conexión)
const unresolvedKeys = new Map<string, string>();

function newKey(): string {
  // randomUUID solo existe en contextos seguros (HTTPS o localhost)
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`;
}

/**
 * POST que no repite su efecto: si la respuesta no llega y la persona reintenta,
 * la misma operación viaja con la misma clave y el servidor devuelve lo ya hecho.
 */
export async function postIdempotent(url: string, body: unknown) {
  const signature = `${url}|${JSON.stringify(body ?? null)}`;
  const key = unresolvedKeys.get(signature) ?? newKey();
  unresolvedKeys.set(signature, key);

  try {
    const response = await api.post(url, body, { headers: { 'Idempotency-Key': key } });
    unresolvedKeys.delete(signature);
    return response;
  } catch (error) {
    // Con respuesta del servidor el resultado se conoce: el siguiente intento es una operación nueva
    if (axios.isAxiosError(error) && error.response) unresolvedKeys.delete(signature);
    throw error;
  }
}
