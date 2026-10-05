import axios from 'axios';
import { loadSession } from '../auth/sessionStorage';

// Formato de error estándar de la API
export interface ApiError {
  code: string;
  message: string;
  details: Record<string, unknown> | null;
  trace_id: string | null;
}

export const UNAUTHORIZED_EVENT = 'erp:unauthorized';

// Mismo origen: el subdominio identifica a la organización en el backend.
export const api = axios.create({
  baseURL: '/api/v1',
  headers: {
    Accept: 'application/json',
  },
});

api.interceptors.request.use((config) => {
  const token = loadSession()?.token;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    // Token vencido o revocado: se cierra la sesión local
    if (axios.isAxiosError(error) && error.response?.status === 401 && loadSession()) {
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

export function apiErrorMessage(error: unknown, fallback: string): string {
  const data = apiError(error);
  if (!data) return fallback;

  if (data.code === 'VALIDATION_ERROR' && data.details) {
    const first = Object.values(data.details)[0];
    if (Array.isArray(first) && typeof first[0] === 'string') return first[0];
  }
  return data.message || fallback;
}
