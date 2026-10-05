import { api } from '../../shared/api/client';

export interface ReportTotals {
  paid_orders: number;
  revenue: string;
  new_enrollments: number;
  purchased_enrollments: number;
  active_students: number;
  completed_enrollments: number;
  certificates_issued: number;
}

export interface SeriesPoint {
  date: string;
  revenue: string;
  paid_orders: number;
  new_enrollments: number;
}

export interface Overview {
  period: { from: string; to: string; days: number; previous_from: string; previous_to: string; timezone: string };
  currency: string;
  totals: ReportTotals;
  // Mismas cifras del periodo anterior de igual duración
  previous: ReportTotals;
  // Situación al momento de consultar, no depende del periodo
  today: {
    pending_orders: number;
    pending_amount: string;
    students_with_access: number;
    published_courses: number;
    valid_certificates: number;
  };
  series: SeriesPoint[];
  top_courses: { course_id: string; title: string; units: number; revenue: string }[];
  completion: { course_id: string; title: string; enrolled: number; completed: number; rate: number }[];
}

// Fecha local en formato AAAA-MM-DD (toISOString la correría a UTC)
export function isoDate(date: Date): string {
  const pad = (value: number) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function parseDay(value: string): Date {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
}

export function formatDay(value: string, withYear = false): string {
  return parseDay(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) });
}

// Variación frente al periodo anterior; null cuando no hay con qué comparar
export function change(current: number, previous: number): number | null {
  if (previous === 0) return current === 0 ? 0 : null;
  return Math.round(((current - previous) / previous) * 100);
}

export interface Bucket {
  label: string;
  value: number;
}

// Un punto por día hasta dos meses; después por semana, y por mes en rangos largos,
// para que las columnas no queden ilegibles.
export function bucketize(series: SeriesPoint[], pick: (point: SeriesPoint) => number): { unit: 'día' | 'semana' | 'mes'; buckets: Bucket[] } {
  if (series.length <= 62) {
    return { unit: 'día', buckets: series.map((point) => ({ label: formatDay(point.date), value: pick(point) })) };
  }

  const monthly = series.length > 190;
  const groups = new Map<string, Bucket>();

  series.forEach((point, index) => {
    const date = parseDay(point.date);
    const key = monthly ? `${date.getFullYear()}-${date.getMonth()}` : String(Math.floor(index / 7));
    const label = monthly
      ? date.toLocaleDateString('es-PE', { month: 'short', year: '2-digit' })
      : `Semana del ${formatDay(series[Math.floor(index / 7) * 7].date)}`;

    const bucket = groups.get(key) ?? { label, value: 0 };
    bucket.value += pick(point);
    groups.set(key, bucket);
  });

  return { unit: monthly ? 'mes' : 'semana', buckets: [...groups.values()] };
}

export const reportsApi = {
  async overview(from: string, to: string) {
    return (await api.get('/reports/overview', { params: { from, to } })).data.data as Overview;
  },
};
