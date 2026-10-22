import React, { useEffect, useState } from 'react';
import { CalendarOff, Plus, Trash2 } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, CardHeader, Field, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { formatDay, schedulesApi, type ScheduleExceptionRow } from '../api';

/**
 * Días sin clase de un docente (permiso, viaje…): ese día no hay clase en
 * vivo, no se toma asistencia ni sale el recordatorio. Los feriados de la
 * academia (Configuración → Feriados) ya cuentan solos.
 */
export const NoClassDaysCard: React.FC<{ teacherId: string; canManage: boolean }> = ({ teacherId, canManage }) => {
  const [rows, setRows] = useState<ScheduleExceptionRow[] | null>(null);
  const [date, setDate] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    schedulesApi
      .exceptions(teacherId)
      .then((data) => active && setRows(data))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar los días sin clase.')));
    return () => {
      active = false;
    };
  }, [teacherId]);

  const run = async (action: () => Promise<ScheduleExceptionRow[]>) => {
    setBusy(true);
    setError(null);
    try {
      setRows(await action());
      setDate('');
      setReason('');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader title="Días sin clase" text="Permisos o viajes del docente: ese día no hay clase en vivo ni recordatorio. Los feriados de la academia ya cuentan solos." />
      <div className="px-5 py-4 space-y-3">
        {error && <Alert>{error}</Alert>}
        {!rows && !error && <LoadingState />}
        {rows && rows.length === 0 && <p className="text-[13px] text-slate-500">No hay días sin clase registrados.</p>}
        {rows && rows.length > 0 && (
          <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200">
            {rows.map((row) => (
              <li key={row.id} className="flex items-center gap-3 px-3.5 py-2 text-[13px]">
                <CalendarOff className="w-4 h-4 text-slate-400" />
                <span className="flex-1 min-w-0">
                  <span className="font-semibold text-slate-900">{formatDay(row.date)}</span>
                  {row.reason && <span className="text-slate-600"> · {row.reason}</span>}
                </span>
                {canManage && (
                  <Button size="sm" variant="ghost" icon={<Trash2 className="w-4 h-4" />} aria-label={`Quitar el ${formatDay(row.date)}`} disabled={busy} onClick={() => void run(() => schedulesApi.removeException(teacherId, row.id))} />
                )}
              </li>
            ))}
          </ul>
        )}
        {canManage && (
          <div className="grid grid-cols-1 sm:grid-cols-[180px_1fr_auto] gap-2.5 items-end">
            <Field label="Día">
              <input type="date" className={INPUT_CLASS} value={date} onChange={(event) => setDate(event.target.value)} />
            </Field>
            <Field label="Motivo (opcional)">
              <input className={INPUT_CLASS} maxLength={150} placeholder="Ej.: viaje, capacitación" value={reason} onChange={(event) => setReason(event.target.value)} />
            </Field>
            <Button icon={<Plus className="w-4 h-4" />} processing={busy} disabled={date === ''} onClick={() => void run(() => schedulesApi.addException(teacherId, date, reason.trim() || null))}>
              Agregar
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
};
